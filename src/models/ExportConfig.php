<?php

namespace justinholtweb\archive\models;

use Craft;
use craft\base\Model;
use craft\models\Site;
use justinholtweb\archive\Plugin;

/**
 * Everything that describes one export run.
 */
class ExportConfig extends Model
{
    /**
     * @var string[] Collector keys to include, e.g. ['entries', 'categories'].
     */
    public array $types = ['entries'];

    /**
     * @var string[] Site handles to export. Empty means the primary site only.
     */
    public array $siteHandles = [];

    /**
     * @var string[] Section handles to limit entries to. Empty means every section.
     */
    public array $sectionHandles = [];

    /**
     * @var string[] Volume handles to limit assets to. Empty means every volume.
     */
    public array $volumeHandles = [];

    /**
     * @var bool Whether disabled elements are included alongside enabled ones.
     */
    public bool $includeDisabled = false;

    /**
     * @var string Output format key, e.g. 'json'.
     */
    public string $format = 'json';

    /**
     * @var bool Whether files from local volumes are copied into the bundle.
     */
    public bool $includeAssetFiles = true;

    /**
     * @var bool Whether files on remote filesystems are downloaded into the bundle.
     */
    public bool $downloadRemoteAssets = false;

    /**
     * @var bool Whether the site's structure is written to schema/.
     */
    public bool $includeSchema = true;

    /**
     * @var string|null Bundle name, without extension. Generated when null.
     */
    public ?string $name = null;

    /**
     * @var int|null Caps the number of elements collected per type. Mostly useful for
     *               trying an export out before running the whole thing.
     */
    public ?int $limit = null;

    /**
     * Builds a config pre-filled from the plugin settings.
     */
    public static function fromSettings(): self
    {
        $settings = Plugin::getInstance()->getSettings();

        $config = new self();
        $config->format = $settings->defaultFormat;
        $config->includeAssetFiles = $settings->includeAssetFiles;
        $config->downloadRemoteAssets = $settings->downloadRemoteAssets;
        $config->includeSchema = $settings->includeSchema;

        return $config;
    }

    /**
     * The sites this export covers, defaulting to the primary site.
     *
     * @return Site[]
     */
    public function getSites(): array
    {
        $sitesService = Craft::$app->getSites();

        if (!$this->siteHandles) {
            return [$sitesService->getPrimarySite()];
        }

        $sites = [];
        foreach ($this->siteHandles as $handle) {
            $site = $sitesService->getSiteByHandle($handle);
            if ($site !== null) {
                $sites[] = $site;
            }
        }

        return $sites ?: [$sitesService->getPrimarySite()];
    }

    /**
     * The bundle name to use, generated from the system name and time when not set.
     */
    public function resolveName(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(Craft::$app->getSystemName()));
        $slug = trim((string)$slug, '-') ?: 'archive';

        return sprintf('%s-%s', $slug, date('Y-m-d-His'));
    }

    public function rules(): array
    {
        return [
            [['types', 'format'], 'required'],
            [['format'], 'string'],
            [['includeDisabled', 'includeAssetFiles', 'downloadRemoteAssets', 'includeSchema'], 'boolean'],
            [['limit'], 'integer', 'min' => 1],
            [['types'], 'validateTypes'],
            [['format'], 'validateFormat'],
        ];
    }

    public function validateTypes(string $attribute): void
    {
        $known = array_keys(Plugin::getInstance()->collectors->all());

        foreach ($this->types as $type) {
            if (!in_array($type, $known, true)) {
                $this->addError($attribute, Craft::t('archive', 'Unknown content type “{type}”.', ['type' => $type]));
            }
        }
    }

    public function validateFormat(string $attribute): void
    {
        if (!Plugin::getInstance()->writers->has($this->format)) {
            $this->addError($attribute, Craft::t('archive', 'Unknown format “{format}”.', ['format' => $this->format]));
        }
    }
}
