<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\services\ProjectConfig;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Describes the shape of the site, not its content.
 *
 * Records tell a target platform what the content is; this tells it what the content
 * *means* — which fields exist, how they're configured, and how they're grouped. Without
 * it an importer is guessing at the model.
 */
class SchemaExporter extends Component
{
    /**
     * Builds the schema and puts it on the context.
     */
    public function export(ExportContext $context): void
    {
        $context->schema = ValueHelper::compact([
            'system' => $this->section($context, 'system settings', $this->system(...)),
            'sites' => $this->section($context, 'sites', $this->sites(...)),
            'sections' => $this->section($context, 'sections', $this->sections(...)),
            'entryTypes' => $this->section($context, 'entry types', $this->entryTypes(...)),
            'fields' => $this->section($context, 'fields', $this->fields(...)),
            'categoryGroups' => $this->section($context, 'category groups', $this->categoryGroups(...)),
            'tagGroups' => $this->section($context, 'tag groups', $this->tagGroups(...)),
            'volumes' => $this->section($context, 'volumes', $this->volumes(...)),
            'filesystems' => $this->section($context, 'filesystems', $this->filesystems(...)),
            'globalSets' => $this->section($context, 'global sets', $this->globalSets(...)),
            'userGroups' => $this->section($context, 'user groups', $this->userGroups(...)),
            'routes' => $this->section($context, 'routes', $this->routes(...)),
            'plugins' => $this->section($context, 'the plugin list', $this->plugins(...)),
        ]);
    }

    /**
     * Runs one part of the schema export, turning a failure into a warning rather than
     * losing the whole bundle over, say, one broken volume.
     */
    private function section(ExportContext $context, string $label, callable $builder): array
    {
        try {
            return $builder();
        } catch (Throwable $e) {
            // Some Craft exceptions (FieldNotFoundException among them) carry no message at
            // all, which turned this warning into a dead end. Fall back to the class name.
            $message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $context->warn(Craft::t('archive', 'Couldn’t export {label} to the schema: {message}', [
                'label' => $label,
                'message' => $message,
            ]));
            Craft::warning("Schema export failed for $label: $message", Plugin::LOG_CATEGORY);
            return [];
        }
    }

    /**
     * Site-wide settings worth carrying. Deliberately hand-picked rather than dumping the
     * general config, which holds the security key and other secrets.
     */
    private function system(): array
    {
        $info = Craft::$app->getInfo();
        $general = Craft::$app->getConfig()->getGeneral();

        return ValueHelper::compact([
            'name' => Craft::$app->getSystemName(),
            'timeZone' => Craft::$app->getTimeZone(),
            'language' => Craft::$app->language,
            // Craft 5.6 made the edition an enum; older 5.x still reports an int.
            'edition' => is_object(Craft::$app->edition)
                ? Craft::$app->edition->name
                : Craft::$app->edition,
            'schemaVersion' => $info->schemaVersion,
            'defaultWeekStartDay' => $general->defaultWeekStartDay,
            'omitScriptNameInUrls' => $general->omitScriptNameInUrls,
            'usePathInfo' => $general->usePathInfo,
        ]);
    }

    private function sites(): array
    {
        return array_map(fn($site) => ValueHelper::compact([
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
            'primary' => $site->primary,
            'baseUrl' => $site->getBaseUrl(),
            'group' => $site->getGroup()->name ?? null,
        ]), Craft::$app->getSites()->getAllSites());
    }

    private function sections(): array
    {
        return array_map(function($section) {
            $siteSettings = [];

            foreach ($section->getSiteSettings() as $settings) {
                $handle = Craft::$app->getSites()->getSiteById($settings->siteId)?->handle;

                if ($handle === null) {
                    continue;
                }

                $siteSettings[$handle] = ValueHelper::compact([
                    'enabledByDefault' => $settings->enabledByDefault,
                    'hasUrls' => $settings->hasUrls,
                    'uriFormat' => $settings->uriFormat,
                    'template' => $settings->template,
                ]);
            }

            return ValueHelper::compact([
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'maxLevels' => $section->maxLevels,
                'propagationMethod' => $section->propagationMethod->value,
                'entryTypes' => array_map(fn($type) => $type->handle, $section->getEntryTypes()),
                'siteSettings' => $siteSettings,
            ]);
        }, Craft::$app->getEntries()->getAllSections());
    }

    private function entryTypes(): array
    {
        return array_map(fn($type) => ValueHelper::compact([
            'handle' => $type->handle,
            'name' => $type->name,
            'hasTitleField' => $type->hasTitleField,
            'titleFormat' => $type->titleFormat,
            'fieldLayout' => $this->fieldLayout($type->getFieldLayout()),
        ]), Craft::$app->getEntries()->getAllEntryTypes());
    }

    private function fields(): array
    {
        return array_map(fn(FieldInterface $field) => ValueHelper::compact([
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $field::class,
            'typeName' => $field::displayName(),
            'instructions' => $field->instructions,
            'translationMethod' => $field->translationMethod,
            'searchable' => $field->searchable,
            'settings' => ValueHelper::jsonSafe($field->getSettings()),
        ]), Craft::$app->getFields()->getAllFields());
    }

    private function categoryGroups(): array
    {
        return array_map(fn($group) => ValueHelper::compact([
            'handle' => $group->handle,
            'name' => $group->name,
            'maxLevels' => $group->maxLevels,
            'fieldLayout' => $this->fieldLayout($group->getFieldLayout()),
        ]), Craft::$app->getCategories()->getAllGroups());
    }

    private function tagGroups(): array
    {
        return array_map(fn($group) => ValueHelper::compact([
            'handle' => $group->handle,
            'name' => $group->name,
            'fieldLayout' => $this->fieldLayout($group->getFieldLayout()),
        ]), Craft::$app->getTags()->getAllTagGroups());
    }

    private function volumes(): array
    {
        return array_map(fn($volume) => ValueHelper::compact([
            'handle' => $volume->handle,
            'name' => $volume->name,
            'fs' => $volume->getFsHandle(),
            'fsType' => $volume->getFs()::class,
            'subpath' => $volume->getSubpath(),
            'local' => $volume->getFs() instanceof \craft\fs\Local,
            'fieldLayout' => $this->fieldLayout($volume->getFieldLayout()),
        ]), Craft::$app->getVolumes()->getAllVolumes());
    }

    private function globalSets(): array
    {
        return array_map(fn($set) => ValueHelper::compact([
            'handle' => $set->handle,
            'name' => $set->name,
            'fieldLayout' => $this->fieldLayout($set->getFieldLayout()),
        ]), Craft::$app->getGlobals()->getAllSets());
    }

    /**
     * Filesystems, by name and type only.
     *
     * Their settings are *not* exported: an S3 or Spaces filesystem keeps its access key
     * and secret there, and a bundle is a file people email each other. What a target
     * platform needs is which volume pointed at what kind of storage, and that's what this
     * gives it.
     */
    private function filesystems(): array
    {
        return array_map(fn($fs) => ValueHelper::compact([
            'handle' => $fs->handle,
            'name' => $fs->name,
            'type' => $fs::class,
            'typeName' => $fs::displayName(),
            'hasUrls' => $fs->hasUrls,
            'url' => $fs->hasUrls ? $fs->getRootUrl() : null,
            'local' => $fs instanceof \craft\fs\Local,
        ]), Craft::$app->getFs()->getAllFilesystems());
    }

    private function userGroups(): array
    {
        $permissions = Craft::$app->getUserPermissions();

        return array_map(fn($group) => ValueHelper::compact([
            'handle' => $group->handle,
            'name' => $group->name,
            'description' => $group->description,
            'permissions' => $permissions->getPermissionsByGroupId($group->id),
        ]), Craft::$app->getUserGroups()->getAllGroups());
    }

    /**
     * Routes defined in the control panel. Routes living in config/routes.php are the
     * project's own code and travel with it, so they're not duplicated here.
     */
    private function routes(): array
    {
        // Read the project config rather than Routes::getProjectConfigRoutes(), which
        // filters down to the current site — an export wants every site's routes.
        $stored = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_ROUTES) ?? [];
        $sites = Craft::$app->getSites();
        $routes = [];

        foreach ($stored as $route) {
            $siteUid = $route['siteUid'] ?? null;

            $routes[] = ValueHelper::compact([
                'uriPattern' => $route['uriPattern'] ?? null,
                'template' => $route['template'] ?? null,
                'site' => $siteUid ? $sites->getSiteByUid($siteUid)?->handle : null,
            ]);
        }

        return $routes;
    }

    /**
     * What was installed when the bundle was made — the quickest answer to "why is this
     * field's value an opaque blob?" on the far side of a migration.
     */
    private function plugins(): array
    {
        $plugins = [];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $plugins[] = ValueHelper::compact([
                'handle' => $plugin->id,
                'name' => $plugin->name,
                'version' => $plugin->getVersion(),
                'developer' => $plugin->developer,
            ]);
        }

        return $plugins;
    }

    /**
     * A field layout as tabs of field handles. UI elements (headings, tips) are left out —
     * they describe the Craft editing experience, not the content model.
     */
    private function fieldLayout(?FieldLayout $layout): array
    {
        if ($layout === null) {
            return [];
        }

        $tabs = [];

        foreach ($layout->getTabs() as $tab) {
            $handles = [];

            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }

                // A layout can outlive the field it points at — an uninstalled plugin leaves
                // its layout elements behind. Skip the orphan rather than losing every entry
                // type in the bundle over one dangling reference.
                try {
                    $handles[] = $element->getField()->handle;
                } catch (Throwable $e) {
                    Craft::warning(
                        sprintf('Skipping orphaned layout element %s: no such field.', $element->fieldUid ?? $element->uid ?? '?'),
                        Plugin::LOG_CATEGORY,
                    );
                }
            }

            if ($handles) {
                $tabs[] = ['name' => $tab->name, 'fields' => $handles];
            }
        }

        return $tabs;
    }
}
