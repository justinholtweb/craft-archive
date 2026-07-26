<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag;
use craft\models\Site;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;

/**
 * Collects tags.
 */
class TagCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'tags';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Tags');
    }

    protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface
    {
        $handles = array_map(
            fn($group) => $group->handle,
            Craft::$app->getTags()->getAllTagGroups()
        );

        if (!$handles) {
            return null;
        }

        return Tag::find()
            ->siteId($site->id)
            ->group($handles)
            ->status($config->includeDisabled ? null : 'enabled')
            ->unique(false);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var Tag $element */
        $group = $element->getGroup();

        return [
            'container' => ValueHelper::compact([
                'group' => $group->handle,
                'groupName' => $group->name,
            ]),
        ];
    }
}
