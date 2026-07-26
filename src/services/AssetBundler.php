<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\FileHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Decides which asset files travel inside a bundle and which are merely pointed at.
 *
 * Files on local volumes are copied in. Files on remote filesystems — S3, Spaces, GCS —
 * are referenced by URL instead, so a bundle for a site with 40GB of media in the cloud
 * is still a few megabytes. That can be overridden per export.
 */
class AssetBundler extends Component
{
    /**
     * Builds the reference for an asset, queueing its file for copying when appropriate.
     */
    public function ref(Asset $asset, ExportContext $context): array
    {
        $volume = $this->volumeHandle($asset);

        $ref = [
            'uid' => $asset->uid,
            'id' => $asset->id,
            'type' => 'asset',
            'title' => (string)$asset,
            'filename' => $asset->filename,
            'kind' => $asset->kind,
            'mimeType' => $asset->getMimeType(),
            'size' => $asset->size !== null ? (int)$asset->size : null,
            'volume' => $volume,
            'folderPath' => $asset->folderPath !== null ? rtrim($asset->folderPath, '/') : null,
            'alt' => $asset->alt,
            'url' => $asset->getUrl(),
            'dateModified' => $asset->dateModified?->format(DATE_ATOM),
        ];

        if ($asset->kind === Asset::KIND_IMAGE) {
            $ref['width'] = $asset->getWidth();
            $ref['height'] = $asset->getHeight();
            $ref['focalPoint'] = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null;
        }

        $bundlePath = $this->queue($asset, $context);
        $ref['bundled'] = $bundlePath !== null;

        if ($bundlePath !== null) {
            $ref['path'] = $bundlePath;
        }

        return array_filter($ref, fn($value) => $value !== null);
    }

    /**
     * Copies an asset's file into the bundle, returning the path it occupies there — or
     * null when the file is being referenced rather than carried.
     *
     * The copy happens here, as the asset is encountered, rather than in a sweep at the
     * end. That's deliberate: the record saying `bundled: true` is written during
     * collection, so if the copy were deferred and then failed, the bundle would claim to
     * contain a file it doesn't have.
     */
    public function queue(Asset $asset, ExportContext $context): ?string
    {
        if (isset($context->queuedFiles[$asset->uid])) {
            return $context->queuedFiles[$asset->uid]['path'];
        }

        if (!$context->config->includeAssetFiles) {
            $context->assetStats['referenced']++;
            return null;
        }

        if (!$this->isLocal($asset) && !$context->config->downloadRemoteAssets) {
            $context->assetStats['referenced']++;
            return null;
        }

        $maxBytes = Plugin::getInstance()->getSettings()->getMaxAssetFileSizeBytes();
        if ($maxBytes !== null && $asset->size !== null && $asset->size > $maxBytes) {
            $context->assetStats['skipped']++;
            $context->warn(Craft::t('archive', 'Referenced rather than bundled (over the size limit): {file}', [
                'file' => $asset->filename,
            ]));
            return null;
        }

        $path = $this->bundlePath($asset);

        if (!$this->copy($asset, $context, $path)) {
            $context->assetStats['skipped']++;
            return null;
        }

        $context->queuedFiles[$asset->uid] = ['asset' => $asset, 'path' => $path];
        $context->assetStats['included']++;
        $context->assetStats['bytes'] += (int)($asset->size ?? 0);

        return $path;
    }

    /**
     * Copies one asset's file into the staging directory.
     *
     * @return bool Whether the file is now in the bundle.
     */
    private function copy(Asset $asset, ExportContext $context, string $path): bool
    {
        if ($context->stagingDir === '') {
            $context->warn(Craft::t('archive', 'Asset files couldn’t be copied: no staging directory was set.'));
            return false;
        }

        $target = $context->stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $source = null;
        $destination = null;

        try {
            FileHelper::createDirectory(dirname($target));

            $source = $asset->getStream();
            $destination = fopen($target, 'wb');

            if ($destination === false) {
                throw new RuntimeException("Couldn’t open $target for writing.");
            }

            stream_copy_to_stream($source, $destination);

            return true;
        } catch (Throwable $e) {
            // A file that can't be read — a broken remote filesystem, a missing file, a
            // permissions problem — is worth a warning, not a failed export.
            $context->warn(Craft::t('archive', 'Couldn’t copy asset file “{file}”: {message}', [
                'file' => $asset->filename,
                'message' => $e->getMessage(),
            ]));
            Craft::warning("Couldn’t copy asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            if (is_file($target)) {
                @unlink($target);
            }

            return false;
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Whether the asset's volume is backed by a local filesystem.
     */
    public function isLocal(Asset $asset): bool
    {
        try {
            return $asset->getVolume()->getFs() instanceof Local;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Where an asset's file lives inside the bundle: assets/<volume>/<folder>/<filename>.
     */
    private function bundlePath(Asset $asset): string
    {
        $segments = ['assets', $this->volumeHandle($asset)];

        $folder = trim((string)$asset->folderPath, '/');
        if ($folder !== '') {
            $segments[] = $folder;
        }

        $segments[] = $asset->filename;

        return implode('/', $segments);
    }

    private function volumeHandle(Asset $asset): string
    {
        try {
            return $asset->getVolume()->handle;
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
