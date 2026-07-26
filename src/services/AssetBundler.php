<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\FileHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
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
     * Queues an asset's file for copying, returning the path it will occupy inside the
     * bundle — or null when the file is being referenced rather than carried.
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

        $context->queuedFiles[$asset->uid] = ['asset' => $asset, 'path' => $path];
        $context->assetStats['included']++;
        $context->assetStats['bytes'] += (int)($asset->size ?? 0);

        return $path;
    }

    /**
     * Copies every queued file into the staging directory.
     */
    public function copyFiles(ExportContext $context, string $stagingDir): void
    {
        foreach ($context->queuedFiles as $uid => $queued) {
            /** @var Asset $asset */
            $asset = $queued['asset'];
            $target = $stagingDir . DIRECTORY_SEPARATOR . $queued['path'];

            try {
                FileHelper::createDirectory(dirname($target));

                $source = $asset->getStream();
                $destination = fopen($target, 'wb');

                if ($destination === false) {
                    throw new \RuntimeException("Couldn’t open $target for writing.");
                }

                stream_copy_to_stream($source, $destination);
                fclose($destination);

                if (is_resource($source)) {
                    fclose($source);
                }
            } catch (Throwable $e) {
                unset($context->queuedFiles[$uid]);
                $context->assetStats['included']--;
                $context->assetStats['skipped']++;
                $context->warn(Craft::t('archive', 'Couldn’t copy asset file “{file}”: {message}', [
                    'file' => $asset->filename,
                    'message' => $e->getMessage(),
                ]));
                Craft::warning("Couldn’t copy asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);
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
