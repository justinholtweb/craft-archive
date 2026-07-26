<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use RuntimeException;
use Throwable;
use yii\base\Component;
use ZipArchive;

/**
 * Assembles the ZIP: a staging directory, a manifest, a readme, and the archive itself.
 */
class BundleBuilder extends Component
{
    public const FORMAT_VERSION = '1.0';

    /**
     * Creates an empty staging directory for a run. Everything in here ends up in the ZIP.
     */
    public function createStagingDir(string $name): string
    {
        return $this->createTempDir($name, 'staging');
    }

    /**
     * Creates a scratch directory for spooled records.
     *
     * Deliberately a sibling of the staging directory rather than a child: the staging
     * directory is zipped wholesale, and the spool is working state, not bundle content.
     */
    public function createSpoolDir(string $name): string
    {
        return $this->createTempDir($name, 'spool');
    }

    private function createTempDir(string $name, string $purpose): string
    {
        $dir = sprintf(
            '%s%s%s-%s-%s',
            rtrim(Plugin::getInstance()->getSettings()->getResolvedTempPath(), '/\\'),
            DIRECTORY_SEPARATOR,
            $this->safeName($name),
            $purpose,
            StringHelper::randomString(8)
        );

        FileHelper::createDirectory($dir);

        return $dir;
    }

    /**
     * The metadata block shared by manifest.json and the master data file.
     */
    public function meta(ExportContext $context): array
    {
        $sites = array_map(fn($site) => [
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
            'primary' => $site->primary,
            'baseUrl' => $site->getBaseUrl(),
        ], Craft::$app->getSites()->getAllSites());

        return [
            'archiveFormatVersion' => self::FORMAT_VERSION,
            'generatedAt' => date(DATE_ATOM),
            'generator' => [
                'plugin' => 'Archive',
                'pluginVersion' => Plugin::getInstance()->getVersion(),
                'craftVersion' => Craft::$app->getVersion(),
                'phpVersion' => PHP_VERSION,
            ],
            'source' => [
                'systemName' => Craft::$app->getSystemName(),
                'primarySiteUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
                'sites' => $sites,
            ],
            'format' => $context->config->format,
        ];
    }

    /**
     * Writes manifest.json — the one file that is always JSON, whatever format the data
     * itself is in, so a reader can always find its bearings.
     *
     * @param string[] $dataFiles
     */
    public function writeManifest(ExportContext $context, string $stagingDir, array $dataFiles): void
    {
        $manifest = $context->meta + [
            'dataFiles' => array_values($dataFiles),
            'contents' => $context->counts(),
            'assets' => $context->assetStats + [
                // Every file that actually made it in, so a reader can check the bundle is
                // complete without walking the ZIP.
                'files' => array_values(array_column($context->queuedFiles, 'path')),
            ],
            'options' => $this->options($context),
            'warnings' => $context->warnings,
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'manifest.json', $json) === false) {
            throw new RuntimeException('Couldn’t write the bundle manifest.');
        }
    }

    /**
     * Writes a plain-language description of the bundle, for whoever opens the ZIP months
     * from now with no idea what produced it.
     *
     * @param string[] $dataFiles
     */
    public function writeReadme(ExportContext $context, string $stagingDir, array $dataFiles): void
    {
        $counts = [];
        foreach ($context->counts() as $type => $count) {
            $counts[] = "  - $type: $count";
        }

        $stats = $context->assetStats;

        $lines = [
            'Archive bundle',
            '==============',
            '',
            'Exported from ' . Craft::$app->getSystemName() . ' on ' . date('Y-m-d H:i:s') . '.',
            'Produced by the Archive plugin for Craft CMS, bundle format v' . self::FORMAT_VERSION . '.',
            '',
            'Contents',
            '--------',
            ...$counts,
            '',
            'Layout',
            '------',
            '  manifest.json  Machine-readable description of this bundle. Always JSON.',
            '  assets/        Asset files, laid out as assets/<volume>/<folder>/<filename>.',
            '',
            'Data files',
            '----------',
            ...array_map(fn(string $file) => '  ' . $file, $dataFiles),
            '',
            ...$this->schemaNote($dataFiles),
            '',
            'Assets',
            '------',
            "  {$stats['included']} file(s) included in this bundle.",
            "  {$stats['referenced']} file(s) referenced by URL only — fetch those from the",
            '  `url` on each asset reference. Files on remote filesystems are referenced',
            '  rather than copied so bundles stay small.',
            "  {$stats['skipped']} file(s) skipped.",
            '',
            'Every record carries a `uid`. Records that share a uid but differ by `site` are',
            'translations of the same element. Relations point at other records by uid.',
            '',
        ];

        if ($context->warnings) {
            $lines[] = 'Warnings';
            $lines[] = '--------';
            foreach ($context->warnings as $warning) {
                $lines[] = '  - ' . $warning;
            }
            $lines[] = '';
        }

        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'README.txt', implode("\n", $lines));
    }

    /**
     * Where the site's structure ended up, which depends on the format: formats that nest
     * carry it inside the data file, CSV has to put it in its own directory.
     *
     * @param string[] $dataFiles
     * @return string[]
     */
    private function schemaNote(array $dataFiles): array
    {
        $inSchemaDir = array_filter($dataFiles, fn(string $file) => str_starts_with($file, 'schema/'));

        if ($inSchemaDir) {
            return [
                'The site’s structure — sections, entry types, field definitions, volumes and',
                'groups — is in the schema/ files listed above.',
                '',
            ];
        }

        return [
            'The site’s structure — sections, entry types, field definitions, volumes and',
            'groups — travels inside the data file above, under `schema`.',
            '',
        ];
    }

    /**
     * Zips the staging directory up into the bundle store, returning the finished path.
     */
    public function zip(string $stagingDir, string $name): string
    {
        $bundleDir = rtrim(Plugin::getInstance()->getSettings()->getResolvedBundlePath(), '/\\');
        FileHelper::createDirectory($bundleDir);

        $target = $bundleDir . DIRECTORY_SEPARATOR . $this->safeName($name) . '.zip';

        if (file_exists($target)) {
            unlink($target);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException("Couldn’t create the bundle at $target (code $opened).");
        }

        $files = FileHelper::findFiles($stagingDir, ['recursive' => true]);
        $prefixLength = strlen($stagingDir) + 1;

        foreach ($files as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, $prefixLength));
            $zip->addFile($file, $relative);
        }

        $zip->close();

        return $target;
    }

    /**
     * Removes a staging directory. Failure here is never worth failing an export over.
     */
    public function cleanup(string $stagingDir): void
    {
        try {
            if (is_dir($stagingDir)) {
                FileHelper::removeDirectory($stagingDir);
            }
        } catch (Throwable $e) {
            Craft::warning("Couldn’t clean up $stagingDir: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        }
    }

    /**
     * The export options, recorded in the manifest so a bundle explains how it was made.
     */
    private function options(ExportContext $context): array
    {
        $config = $context->config;

        return [
            'types' => $config->types,
            'sites' => array_map(fn($site) => $site->handle, $config->getSites()),
            'sections' => $config->sectionHandles,
            'volumes' => $config->volumeHandles,
            'includeDisabled' => $config->includeDisabled,
            'includeAssetFiles' => $config->includeAssetFiles,
            'downloadRemoteAssets' => $config->downloadRemoteAssets,
            'includeSchema' => $config->includeSchema,
            'limit' => $config->limit,
        ];
    }

    /**
     * Keeps a user-supplied bundle name from wandering out of the bundle directory.
     */
    private function safeName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $safe = trim((string)$safe, '-._');

        return $safe !== '' ? $safe : 'archive';
    }
}
