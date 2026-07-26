<?php

namespace justinholtweb\archive\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\FieldSerializer;
use Throwable;
use verbb\hyper\fields\HyperField;

/**
 * Verbb Hyper links.
 *
 * Hyper stores an element link as a bare Craft element ID — `"linkValue": [12]` — which is
 * meaningless once the content leaves this install. Each link is resolved to its target
 * element so the bundle carries a uid and a URL instead.
 */
class HyperSerializer implements ValueSerializerInterface
{
    public function supports(FieldInterface $field): bool
    {
        return class_exists(HyperField::class) && $field instanceof HyperField;
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
                'type' => $this->type($link),
                'text' => $link->getLinkText() ?: null,
                'url' => $this->url($link),
                'target' => $this->target($link, $context),
                'newWindow' => $link->newWindow ?? null,
                'title' => $link->linkTitle ?? null,
                'classes' => $link->classes ?? null,
                'ariaLabel' => $link->ariaLabel ?? null,
            ]);
        }

        return ['kind' => FieldSerializer::KIND_LINK, 'value' => $links];
    }

    /**
     * @return list<object>
     */
    private function links(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'getLinks')) {
            return $value->getLinks();
        }

        return is_iterable($value) ? iterator_to_array($value) : [];
    }

    /**
     * Hyper's link classes are namespaced — `verbb\hyper\links\Entry`. The short name is
     * what's actually useful to a reader.
     */
    private function type(object $link): string
    {
        $parts = explode('\\', $link::class);

        return lcfirst(end($parts));
    }

    private function url(object $link): ?string
    {
        try {
            return $link->getLinkUrl() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function target(object $link, ExportContext $context): ?array
    {
        try {
            $target = method_exists($link, 'getElement') ? $link->getElement() : null;
        } catch (Throwable) {
            return null;
        }

        return $target instanceof ElementInterface ? RefHelper::ref($target, $context) : null;
    }
}
