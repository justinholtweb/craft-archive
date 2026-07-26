<?php

namespace justinholtweb\archive\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\FieldSerializer;
use nystudio107\seomatic\fields\SeoSettings;

/**
 * SEOmatic settings.
 *
 * The raw value is a five-kilobyte meta bundle, most of it computed containers and Twig
 * expressions like `{{ seomatic.helper.socialTransform(211, …) }}` — an asset ID wrapped in
 * a template that only means something inside this install.
 *
 * What comes out instead is the SEO metadata a target platform would actually want, with
 * image IDs resolved to asset references. The user's own `metaBundleSettings` are kept
 * alongside so nothing they configured is lost; the derived containers are not.
 */
class SeomaticSerializer implements ValueSerializerInterface
{
    /**
     * Where the images live in the bundle settings, and what to call them on the way out.
     */
    private const IMAGE_SOURCES = [
        'seo' => 'seoImageIds',
        'og' => 'ogImageIds',
        'twitter' => 'twitterImageIds',
    ];

    public function supports(FieldInterface $field): bool
    {
        return class_exists(SeoSettings::class) && $field instanceof SeoSettings;
    }

    public function serialize(
        mixed $value,
        ElementInterface $element,
        FieldInterface $field,
        ExportContext $context,
    ): array {
        $bundle = ValueHelper::jsonSafe($field->serializeValue($value, $element));

        if (!is_array($bundle)) {
            return ['kind' => FieldSerializer::KIND_SEO, 'value' => null];
        }

        $global = $bundle['metaGlobalVars'] ?? [];
        $settings = $bundle['metaBundleSettings'] ?? [];

        $seo = ValueHelper::compact([
            'title' => $this->plain($global['seoTitle'] ?? null),
            'description' => $this->plain($global['seoDescription'] ?? null),
            'keywords' => $this->plain($global['seoKeywords'] ?? null),
            'robots' => $this->plain($global['robots'] ?? null),
            'canonicalUrl' => $this->plain($global['canonicalUrl'] ?? null),
            'images' => $this->images($settings, $context),
            'settings' => ValueHelper::compact($this->withoutImageIds($settings)),
        ]);

        return ['kind' => FieldSerializer::KIND_SEO, 'value' => $seo ?: null];
    }

    /**
     * Resolves the configured image IDs to asset references.
     *
     * @return array<string, array>
     */
    private function images(array $settings, ExportContext $context): array
    {
        $images = [];

        foreach (self::IMAGE_SOURCES as $key => $settingName) {
            $ids = array_filter((array)($settings[$settingName] ?? []));

            if (!$ids) {
                continue;
            }

            $assets = Asset::find()->id($ids)->status(null)->all();

            foreach ($assets as $asset) {
                $images[$key][] = RefHelper::ref($asset, $context);
            }
        }

        return $images;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutImageIds(array $settings): array
    {
        return array_diff_key($settings, array_flip(array_values(self::IMAGE_SOURCES)));
    }

    /**
     * SEOmatic stores some values as Twig expressions. Those are this install's plumbing,
     * not content, so they're left out rather than exported as strings that look like data.
     */
    private function plain(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return str_contains($value, '{{') || str_contains($value, '{%') ? null : $value;
    }
}
