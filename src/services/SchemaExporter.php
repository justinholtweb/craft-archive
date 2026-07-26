<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
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
            'sites' => $this->section($context, 'sites', $this->sites(...)),
            'sections' => $this->section($context, 'sections', $this->sections(...)),
            'entryTypes' => $this->section($context, 'entry types', $this->entryTypes(...)),
            'fields' => $this->section($context, 'fields', $this->fields(...)),
            'categoryGroups' => $this->section($context, 'category groups', $this->categoryGroups(...)),
            'tagGroups' => $this->section($context, 'tag groups', $this->tagGroups(...)),
            'volumes' => $this->section($context, 'volumes', $this->volumes(...)),
            'globalSets' => $this->section($context, 'global sets', $this->globalSets(...)),
            'userGroups' => $this->section($context, 'user groups', $this->userGroups(...)),
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
            $context->warn(Craft::t('archive', 'Couldn’t export {label} to the schema: {message}', [
                'label' => $label,
                'message' => $e->getMessage(),
            ]));
            Craft::warning("Schema export failed for $label: {$e->getMessage()}", Plugin::LOG_CATEGORY);
            return [];
        }
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

    private function userGroups(): array
    {
        return array_map(fn($group) => ValueHelper::compact([
            'handle' => $group->handle,
            'name' => $group->name,
            'description' => $group->description,
        ]), Craft::$app->getUserGroups()->getAllGroups());
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
                if ($element instanceof CustomField) {
                    $handles[] = $element->getField()->handle;
                }
            }

            if ($handles) {
                $tabs[] = ['name' => $tab->name, 'fields' => $handles];
            }
        }

        return $tabs;
    }
}
