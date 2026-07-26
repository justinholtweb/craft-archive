<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use justinholtweb\archive\events\ExportEvent;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
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
     */
    public function run(ExportConfig $config): BundleRecord
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

        $context = new ExportContext($config);
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

        foreach ($context->config->types as $key) {
            $collector = $registry->get($key);

            if ($collector === null) {
                $context->warn(Craft::t('archive', 'Skipped unknown content type “{type}”.', ['type' => $key]));
                continue;
            }

            $collector->collect($context);

            // Make sure a type that matched nothing still shows up in the manifest as zero,
            // rather than vanishing.
            $context->records[$key] ??= [];
        }
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
