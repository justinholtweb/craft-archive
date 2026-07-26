<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;

/**
 * Collects assets, and gets their files into the bundle.
 *
 * Relations already pull in whichever assets entries point at; this is what makes sure a
 * file nobody links to still travels.
 */
class AssetCollector extends BaseCollector
{
    /**
     * Keys the asset bundler produces that describe the file rather than identify the
     * element — the envelope already carries uid, id, type and title.
     */
    private const FILE_KEYS = [
        'filename', 'kind', 'mimeType', 'size', 'alt', 'url', 'dateModified',
        'width', 'height', 'focalPoint', 'bundled', 'path',
    ];

    public static function key(): string
    {
        return 'assets';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Assets');
    }

    protected function query(ExportContext $context, Site $site): ?ElementQueryInterface
    {
        $handles = $context->config->volumeHandles ?: array_map(
            fn($volume) => $volume->handle,
            Craft::$app->getVolumes()->getAllVolumes()
        );

        if (!$handles) {
            return null;
        }

        return Asset::find()
            ->siteId($site->id)
            ->volume($handles)
            ->status($context->config->includeDisabled ? null : 'enabled')
            ->unique(false);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var Asset $element */

        // Going through the bundler is what queues the file for copying — and it dedupes,
        // so an asset an entry already referenced isn't copied twice.
        $ref = Plugin::getInstance()->assets->ref($element, $context);

        $file = array_intersect_key($ref, array_flip(self::FILE_KEYS));

        return [
            'container' => ValueHelper::compact([
                'volume' => $ref['volume'] ?? null,
                'volumeName' => $this->volumeName($element),
                'folderPath' => $ref['folderPath'] ?? null,
            ]),
            'file' => $file,
        ];
    }

    private function volumeName(Asset $asset): ?string
    {
        try {
            return $asset->getVolume()->name;
        } catch (\Throwable) {
            return null;
        }
    }
}
