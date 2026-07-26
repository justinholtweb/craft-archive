<?php

namespace justinholtweb\archive\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Builds a bundle from the command line — the way you'd want to run a big export, or one
 * on a schedule.
 *
 *     php craft archive/export
 *     php craft archive/export --types=entries,assets --format=csv
 *     php craft archive/export --sites=default,fr --sections=news --name=news-only
 */
class ExportController extends Controller
{
    /**
     * @var string|null Comma-separated content types. Defaults to every available type.
     */
    public ?string $types = null;

    /**
     * @var string|null Output format. Defaults to the configured default.
     */
    public ?string $format = null;

    /**
     * @var string|null Comma-separated site handles. Defaults to the primary site.
     */
    public ?string $sites = null;

    /**
     * @var string|null Comma-separated section handles. Defaults to every section.
     */
    public ?string $sections = null;

    /**
     * @var string|null Comma-separated volume handles. Defaults to every volume.
     */
    public ?string $volumes = null;

    /**
     * @var string|null Bundle name, without extension.
     */
    public ?string $name = null;

    /**
     * @var bool Whether disabled elements are included.
     */
    public bool $includeDisabled = false;

    /**
     * @var bool Whether asset files from local volumes are copied in.
     */
    public ?bool $assets = null;

    /**
     * @var bool Whether the site's structure is included.
     */
    public ?bool $schema = null;

    /**
     * @var int|null Cap on elements collected per type.
     */
    public ?int $limit = null;

    /**
     * @var bool Queue the export instead of running it now.
     */
    public bool $queue = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'types', 'format', 'sites', 'sections', 'volumes', 'name',
            'includeDisabled', 'assets', 'schema', 'limit', 'queue',
        ]);
    }

    /**
     * Exports content into a bundle.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $config = $this->buildConfig();

        if (!$config->validate()) {
            foreach ($config->getErrorSummary(true) as $error) {
                $this->stderr("$error\n", Console::FG_RED);
            }

            $this->stdout("\nAvailable types:   " . implode(', ', array_keys($plugin->collectors->all())) . "\n");
            $this->stdout('Available formats: ' . implode(', ', array_keys($plugin->writers->all())) . "\n");

            return ExitCode::USAGE;
        }

        if ($this->queue) {
            $plugin->export->queue($config);
            $this->stdout("Export queued.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $estimate = $plugin->export->estimate($config);
        $this->stdout("Exporting about $estimate record(s)…\n");

        $lastReport = 0;

        try {
            $bundle = $plugin->export->run($config, function(string $type, int $total) use (&$lastReport) {
                // Reporting every record would spend more time on output than on work.
                if ($total - $lastReport >= 100) {
                    $this->stdout("  $total records…\n");
                    $lastReport = $total;
                }
            });
        } catch (Throwable $e) {
            $this->stderr("Export failed: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($bundle->getCountsArray() as $type => $count) {
            $this->stdout(sprintf("  %-14s %d\n", $type, $count));
        }

        foreach ($bundle->getWarningsArray() as $warning) {
            $this->stdout("  warning: $warning\n", Console::FG_YELLOW);
        }

        $path = $plugin->bundles->path($bundle);
        $this->stdout("\nWrote " . ($path ?? $bundle->filename) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function buildConfig(): ExportConfig
    {
        $plugin = Plugin::getInstance();
        $config = ExportConfig::fromSettings();

        $config->types = $this->split($this->types) ?: array_keys($plugin->collectors->all());
        $config->siteHandles = $this->split($this->sites);
        $config->sectionHandles = $this->split($this->sections);
        $config->volumeHandles = $this->split($this->volumes);
        $config->includeDisabled = $this->includeDisabled;
        $config->name = $this->name;
        $config->limit = $this->limit;

        if ($this->format !== null) {
            $config->format = $this->format;
        }

        if ($this->assets !== null) {
            $config->includeAssetFiles = $this->assets;
        }

        if ($this->schema !== null) {
            $config->includeSchema = $this->schema;
        }

        return $config;
    }

    /**
     * @return string[]
     */
    private function split(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
