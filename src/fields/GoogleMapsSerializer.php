<?php

namespace justinholtweb\archive\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use doublesecretagency\googlemaps\fields\AddressField;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\FieldSerializer;

/**
 * Google Maps address fields.
 *
 * The stored value is mostly portable already, but it's mixed in with this install's own
 * row, element, site and field IDs. Those are dropped and the address itself is kept.
 */
class GoogleMapsSerializer implements ValueSerializerInterface
{
    /**
     * Keys that only mean anything inside the install that produced them.
     */
    private const LOCAL_KEYS = ['id', 'elementId', 'siteId', 'fieldId', 'distance', 'enabledSubfields'];

    public function supports(FieldInterface $field): bool
    {
        return class_exists(AddressField::class) && $field instanceof AddressField;
    }

    public function serialize(
        mixed $value,
        ElementInterface $element,
        FieldInterface $field,
        ExportContext $context,
    ): array {
        $address = ValueHelper::jsonSafe($field->serializeValue($value, $element));

        if (!is_array($address)) {
            return ['kind' => FieldSerializer::KIND_ADDRESS, 'value' => null];
        }

        $address = array_diff_key($address, array_flip(self::LOCAL_KEYS));

        return [
            'kind' => FieldSerializer::KIND_ADDRESS,
            'value' => ValueHelper::compact($address) ?: null,
        ];
    }
}
