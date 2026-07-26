<?php

namespace justinholtweb\archive\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Archive plugin settings.
 */
class Settings extends Model
{
    /**
     * @var string Where finished bundles are stored. Supports Craft aliases and
     *             environment variables.
     */
    public string $bundlePath = '@storage/archive/bundles';

    /**
     * @var string Where bundles are staged while being built. Cleared after each run.
     */
    public string $tempPath = '@storage/archive/tmp';

    /**
     * @var string Default output format for new exports.
     */
    public string $defaultFormat = 'json';

    /**
     * @var bool Whether files from local volumes are copied into the bundle by default.
     *           When false, every asset is exported as metadata plus a URL.
     */
    public bool $includeAssetFiles = true;

    /**
     * @var bool Whether files on remote filesystems (S3, Spaces, GCS…) are downloaded into
     *           the bundle. Off by default: remote assets are referenced by URL so bundles
     *           stay small.
     */
    public bool $downloadRemoteAssets = false;

    /**
     * @var int Files larger than this many megabytes are referenced rather than copied.
     *          0 disables the limit.
     */
    public int $maxAssetFileSize = 256;

    /**
     * @var bool Whether the site's structure (sections, fields, volumes, sites…) is written
     *           to the bundle's schema/ directory.
     */
    public bool $includeSchema = true;

    /**
     * @var bool Whether user accounts may be exported at all. Off by default because a
     *           bundle is a downloadable ZIP containing personal data. Password hashes are
     *           never exported, regardless of this setting.
     */
    public bool $allowUserExport = false;

    /**
     * @var int Days to keep finished bundles before pruning. 0 keeps them indefinitely.
     */
    public int $retentionDays = 30;

    /**
     * @var int Maximum number of bundles to keep, regardless of age. 0 keeps them all.
     */
    public int $retentionCount = 20;

    /**
     * @var int How many elements are loaded at a time while exporting.
     */
    public int $batchSize = 100;

    /**
     * @var string Log verbosity. One of: error, warning, info, debug.
     */
    public string $logLevel = 'info';

    /**
     * Resolves {@see $bundlePath} to an absolute filesystem path.
     */
    public function getResolvedBundlePath(): string
    {
        return Craft::getAlias(App::parseEnv($this->bundlePath ?: '@storage/archive/bundles'));
    }

    /**
     * Resolves {@see $tempPath} to an absolute filesystem path.
     */
    public function getResolvedTempPath(): string
    {
        return Craft::getAlias(App::parseEnv($this->tempPath ?: '@storage/archive/tmp'));
    }

    /**
     * The max asset file size in bytes, or null when unlimited.
     */
    public function getMaxAssetFileSizeBytes(): ?int
    {
        return $this->maxAssetFileSize > 0 ? $this->maxAssetFileSize * 1024 * 1024 : null;
    }

    public function rules(): array
    {
        // Nothing here is `required` on purpose: a required rule fails validation for the
        // whole settings model, which would block saving any setting at all.
        return [
            [['bundlePath', 'tempPath', 'defaultFormat', 'logLevel'], 'string'],
            [['maxAssetFileSize', 'retentionDays', 'retentionCount'], 'integer', 'min' => 0],
            [['batchSize'], 'integer', 'min' => 1],
            [['includeAssetFiles', 'downloadRemoteAssets', 'includeSchema', 'allowUserExport'], 'boolean'],
            [['logLevel'], 'in', 'range' => ['error', 'warning', 'info', 'debug']],
        ];
    }
}
