<?php

namespace justinholtweb\archive\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\FieldSerializer;
use justinholtweb\freelink\fields\FreeLinkField;
use Throwable;

/**
 * FreeLink links.
 *
 * FreeLink keeps element targets in its own relations table, and its `toArray()` nulls the
 * value out — an entry link serializes to `{"type":"entry","value":null}`, which is no use
 * to anyone. The live link objects still know their target, so those are read instead.
 */
class FreeLinkSerializer implements ValueSerializerInterface
{
    public function supports(FieldInterface $field): bool
    {
        return class_exists(FreeLinkField::class) && $field instanceof FreeLinkField;
    }

    public function serialize(
        mixed $value,
        ElementInterface $element,
        FieldInterface $field,
        ExportContext $context,
    ): array {
        $links = [];

        foreach ($this->links($value) as $link) {
            $links[] = ValueHelper::compact([
                'type' => $link->type ?? null,
                'text' => $this->call($link, 'getText'),
                'url' => $this->call($link, 'getUrl'),
                'target' => $this->target($link, $context),
                'newWindow' => $link->newWindow ?? null,
                'title' => $link->title ?? null,
                'classes' => $link->classes ?? null,
                'ariaLabel' => $link->ariaLabel ?? null,
                'rel' => $link->rel ?? null,
                'urlSuffix' => $link->urlSuffix ?? null,
            ]);
        }

        return ['kind' => FieldSerializer::KIND_LINK, 'value' => $links];
    }

    /**
     * @return list<object>
     */
    private function links(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'getAll')) {
            return $value->getAll();
        }

        if (is_object($value) && !is_iterable($value)) {
            return [$value];
        }

        return is_iterable($value) ? iterator_to_array($value) : [];
    }

    private function target(object $link, ExportContext $context): ?array
    {
        $target = $this->call($link, 'getElement');

        return $target instanceof ElementInterface ? RefHelper::ref($target, $context) : null;
    }

    private function call(object $link, string $method): mixed
    {
        if (!method_exists($link, $method)) {
            return null;
        }

        try {
            return $link->$method() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
