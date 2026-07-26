<?php

namespace justinholtweb\archive;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\archive\models\Settings;
use justinholtweb\archive\services\AssetBundler;
use justinholtweb\archive\services\BundleBuilder;
use justinholtweb\archive\services\Bundles;
use justinholtweb\archive\services\CollectorRegistry;
use justinholtweb\archive\services\Export;
use justinholtweb\archive\services\FieldSerializer;
use justinholtweb\archive\services\SchemaExporter;
use justinholtweb\archive\services\WriterRegistry;
use yii\base\Event;

/**
 * Archive — export a Craft site's content into portable bundles.
 *
 * @property-read Export $export
 * @property-read Bundles $bundles
 * @property-read BundleBuilder $builder
 * @property-read CollectorRegistry $collectors
 * @property-read WriterRegistry $writers
 * @property-read FieldSerializer $fields
 * @property-read AssetBundler $assets
 * @property-read SchemaExporter $schema
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_EXPORT = 'archive:export';
    public const PERMISSION_MANAGE = 'archive:manage';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'archive';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'export' => Export::class,
                'bundles' => Bundles::class,
                'builder' => BundleBuilder::class,
                'collectors' => CollectorRegistry::class,
                'writers' => WriterRegistry::class,
                'fields' => FieldSerializer::class,
                'assets' => AssetBundler::class,
                'schema' => SchemaExporter::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
    }

    /**
     * Sends Archive's log entries to a dedicated storage/logs/archive.log target.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [];
        if ($user->checkPermission(self::PERMISSION_EXPORT)) {
            $subnav['export'] = ['label' => Craft::t('archive', 'Export'), 'url' => 'archive/export'];
        }
        $subnav['bundles'] = ['label' => Craft::t('archive', 'Bundles'), 'url' => 'archive/bundles'];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('archive', 'Settings'), 'url' => 'archive/settings'];
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('archive/_settings', [
            'settings' => $this->getSettings(),
            'formatOptions' => $this->writers->options(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('archive/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['archive'] = 'archive/export/index';
                $event->rules['archive/export'] = 'archive/export/index';
                $event->rules['archive/bundles'] = 'archive/bundles/index';
                $event->rules['archive/bundles/<id:\d+>'] = 'archive/bundles/detail';
                $event->rules['archive/settings'] = 'archive/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('archive', 'Archive'),
                    'permissions' => [
                        self::PERMISSION_EXPORT => [
                            'label' => Craft::t('archive', 'Create export bundles'),
                        ],
                        self::PERMISSION_MANAGE => [
                            'label' => Craft::t('archive', 'Download and delete bundles'),
                        ],
                    ],
                ];
            }
        );
    }
}
