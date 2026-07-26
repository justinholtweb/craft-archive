<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use justinholtweb\archive\events\ExportEvent;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\models\RecordStore;
use justinholtweb\archive\Plugin;
use justinholtweb\archive\queue\ExportJob;
use justinholtweb\archive\records\BundleRecord;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Runs an export end to end: collect, write, bundle.
 */
class Export extends Component
{
    /**
     * @event ExportEvent Raised before an export runs. Set `$event->isValid = false` to
     *        cancel it.
     */
    public const EVENT_BEFORE_EXPORT = 'beforeExport';

    /**
     * @event ExportEvent Raised once a bundle has been written.
     */
    public const EVENT_AFTER_EXPORT = 'afterExport';

    /**
     * Produces a bundle, returning its ledger row.
     *
     * @param callable(string, int): void|null $onProgress Called as each record is
     *        collected, with the record type and the running total.
     */
    public function run(ExportConfig $config, ?callable $onProgress = null): BundleRecord
    {
        $plugin = Plugin::getInstance();
        $name = $config->resolveName();

        $record = new BundleRecord([
            'name' => $name,
            'format' => $config->format,
            'status' => BundleRecord::STATUS_RUNNING,
            'config' => Json::encode($config->toArray()),
            'creatorId' => Craft::$app->getUser()->getIdentity()?->id,
        ]);
        $record->save(false);

        $store = new RecordStore($plugin->builder->createSpoolDir($name));
        $context = new ExportContext($config, $store);
        $context->onRecord = $onProgress;
        $stagingDir = null;

        $event = new ExportEvent(['config' => $config, 'context' => $context]);
        $this->trigger(self::EVENT_BEFORE_EXPORT, $event);

        if (!$event->isValid) {
            $record->status = BundleRecord::STATUS_FAILED;
            $record->error = 'Cancelled by a beforeExport handler.';
            $record->save(false);

            return $record;
        }

        try {
            $this->collect($context);

            if ($config->includeSchema) {
                $plugin->schema->export($context);
            }

            $context->meta = $plugin->builder->meta($context);

            $stagingDir = $plugin->builder->createStagingDir($name);

            $writer = $plugin->writers->get($config->format);
            if ($writer === null) {
                throw new RuntimeException("No writer is registered for the “{$config->format}” format.");
            }

            $dataFiles = $writer->write($context, $stagingDir);

            $plugin->assets->copyFiles($context, $stagingDir);

            $plugin->builder->writeManifest($context, $stagingDir, $dataFiles);
            $plugin->builder->writeReadme($context, $stagingDir, $dataFiles);

            $zipPath = $plugin->builder->zip($stagingDir, $name);

            $record->filename = basename($zipPath);
            $record->size = filesize($zipPath) ?: null;
            $record->counts = Json::encode($context->counts());
            $record->warnings = Json::encode($context->warnings);
            $record->status = BundleRecord::STATUS_COMPLETED;
            $record->save(false);

            Craft::info(sprintf(
                'Exported bundle %s (%d records, %d asset files).',
                $record->filename,
                $context->totalRecords(),
                $context->assetStats['included']
            ), Plugin::LOG_CATEGORY);

            $this->trigger(self::EVENT_AFTER_EXPORT, new ExportEvent([
                'config' => $config,
                'context' => $context,
                'bundle' => $record,
            ]));

            return $record;
        } catch (Throwable $e) {
            $record->status = BundleRecord::STATUS_FAILED;
            $record->error = $e->getMessage();
            $record->warnings = Json::encode($context->warnings);
            $record->save(false);

            Craft::error("Export failed: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            throw $e;
        } finally {
            $store->cleanup();

            if ($stagingDir !== null) {
                $plugin->builder->cleanup($stagingDir);
            }
        }
    }

    /**
     * Runs each selected collector, in the order they were requested.
     */
    private function collect(ExportContext $context): void
    {
        $registry = Plugin::getInstance()->collectors;

        // Run in registry order rather than whatever order the form posted, so bundles are
        // laid out the same way every time.
        $requested = $context->config->types;
        $ordered = array_values(array_filter(
            array_keys($registry->all()),
            fn(string $key) => in_array($key, $requested, true)
        ));
        $unknown = array_values(array_diff($requested, $ordered));

        foreach ([...$ordered, ...$unknown] as $key) {
            $collector = $registry->get($key);

            if ($collector === null) {
                $context->warn(Craft::t('archive', 'Skipped unknown content type “{type}”.', ['type' => $key]));
                continue;
            }

            // Registered up front so a type that matches nothing still shows up in the
            // manifest as zero, rather than vanishing.
            $context->records->register($key);

            $collector->collect($context);
        }
    }

    /**
     * Pushes an export onto the queue and returns the job ID.
     */
    public function queue(ExportConfig $config): ?int
    {
        return Craft::$app->getQueue()->push(new ExportJob([
            'config' => $config->toArray(),
            'estimate' => $this->estimate($config),
            'description' => Craft::t('archive', 'Building bundle “{name}”', [
                'name' => $config->resolveName(),
            ]),
        ]));
    }

    /**
     * Roughly how many records a config will produce. Counts rather than collects, so it's
     * cheap enough to run before queueing.
     */
    public function estimate(ExportConfig $config): int
    {
        $registry = Plugin::getInstance()->collectors;
        $total = 0;

        foreach ($config->types as $key) {
            try {
                $total += $registry->get($key)?->estimate($config) ?? 0;
            } catch (Throwable $e) {
                Craft::warning("Couldn’t estimate $key: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }

        return $total;
    }

    /**
     * Deletes bundles that have aged out, honouring both retention settings.
     */
    public function prune(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $bundles = Plugin::getInstance()->bundles;
        $deleted = 0;

        if ($settings->retentionDays > 0) {
            $cutoff = Db::prepareDateForDb(new \DateTime("-{$settings->retentionDays} days"));

            foreach (BundleRecord::find()->where(['<', 'dateCreated', $cutoff])->all() as $record) {
                $bundles->delete($record);
                $deleted++;
            }
        }

        if ($settings->retentionCount > 0) {
            $surplus = BundleRecord::find()
                ->orderBy(['dateCreated' => SORT_DESC])
                ->offset($settings->retentionCount)
                ->limit(1000)
                ->all();

            foreach ($surplus as $record) {
                $bundles->delete($record);
                $deleted++;
            }
        }

        return $deleted;
    }
}
