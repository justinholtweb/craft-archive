<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use Throwable;

/**
 * Collects categories, keeping the tree structure intact through parent references.
 */
class CategoryCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'categories';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Categories');
    }

    protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface
    {
        $handles = array_map(
            fn($group) => $group->handle,
            Craft::$app->getCategories()->getAllGroups()
        );

        if (!$handles) {
            return null;
        }

        return Category::find()
            ->siteId($site->id)
            ->group($handles)
            ->status($config->includeDisabled ? null : 'enabled')
            ->unique(false);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var Category $element */
        $group = $element->getGroup();

        $attributes = [
            'container' => ValueHelper::compact([
                'group' => $group->handle,
                'groupName' => $group->name,
            ]),
            'level' => $element->level,
        ];

        $parent = $this->parent($element);
        if ($parent !== null) {
            $attributes['parent'] = RefHelper::ref($parent, $context);
        }

        return $attributes;
    }

    private function parent(Category $category): ?ElementInterface
    {
        if (($category->level ?? 1) <= 1) {
            return null;
        }

        try {
            return $category->getParent();
        } catch (Throwable) {
            return null;
        }
    }
}
