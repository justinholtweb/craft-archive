<?php

namespace justinholtweb\archive\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\archive\models\ExportContext;

/**
 * Teaches Archive how to make one field type's values portable.
 *
 * Without one of these, an unrecognised field still exports — through the field's own
 * `serializeValue()`, as `raw` — but what comes out is whatever that plugin happens to
 * store, which usually means Craft element IDs that mean nothing anywhere else.
 *
 * Register your own with
 * {@see \justinholtweb\archive\services\FieldSerializer::EVENT_REGISTER_VALUE_SERIALIZERS}.
 */
interface ValueSerializerInterface
{
    /**
     * Whether this serializer handles the given field.
     *
     * Implementations for optional plugins should guard on `class_exists()` so they're
     * inert when that plugin isn't installed.
     */
    public function supports(FieldInterface $field): bool;

    /**
     * Converts a value into something portable.
     *
     * @return array{kind: string, value: mixed}
     */
    public function serialize(
        mixed $value,
        ElementInterface $element,
        FieldInterface $field,
        ExportContext $context,
    ): array;
}
