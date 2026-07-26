<?php

namespace justinholtweb\archive\services;

use Craft;
use justinholtweb\archive\Plugin;
use justinholtweb\archive\records\BundleRecord;
use Throwable;
use yii\base\Component;

/**
 * The bundle ledger: what has been exported, where the file is, and getting rid of it.
 */
class Bundles extends Component
{
    /**
     * @return BundleRecord[] Newest first.
     */
    public function getAll(int $limit = 100): array
    {
        return BundleRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getById(int $id): ?BundleRecord
    {
        return BundleRecord::findOne(['id' => $id]);
    }

    /**
     * The bundle file's absolute path, or null when the row has no file (a failed run, or
     * a file that has since been deleted from disk).
     */
    public function path(BundleRecord $record): ?string
    {
        if (!$record->filename) {
            return null;
        }

        $path = rtrim(Plugin::getInstance()->getSettings()->getResolvedBundlePath(), '/\\')
            . DIRECTORY_SEPARATOR
            . basename($record->filename);

        return is_file($path) ? $path : null;
    }

    public function exists(BundleRecord $record): bool
    {
        return $this->path($record) !== null;
    }

    /**
     * Deletes a bundle's file and its ledger row.
     */
    public function delete(BundleRecord $record): bool
    {
        $path = $this->path($record);

        if ($path !== null) {
            try {
                unlink($path);
            } catch (Throwable $e) {
                Craft::warning("Couldn’t delete bundle file $path: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            }
        }

        return (bool)$record->delete();
    }
}
