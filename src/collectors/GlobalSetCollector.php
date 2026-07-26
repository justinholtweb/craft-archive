<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\GlobalSet;
use craft\models\Site;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;

/**
 * Collects global sets — the site-wide content that isn't attached to any one page.
 */
class GlobalSetCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'globals';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Globals');
    }

    protected function query(ExportContext $context, Site $site): ?ElementQueryInterface
    {
        return GlobalSet::find()
            ->siteId($site->id)
            ->status(null)
            ->unique(false);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var GlobalSet $element */
        return [
            'container' => ValueHelper::compact([
                'handle' => $element->handle,
                'name' => $element->name,
            ]),
        ];
    }
}
