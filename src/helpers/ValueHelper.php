<?php

namespace justinholtweb\archive\helpers;

use craft\base\Serializable;
use DateTimeInterface;
use Money\Money;
use yii\base\Arrayable;

/**
 * Turns whatever a field handed us into something a JSON, XML, YAML or CSV writer can
 * actually emit.
 */
abstract class ValueHelper
{
    /**
     * How deep to recurse before giving up. Guards against a field value that references
     * itself.
     */
    private const MAX_DEPTH = 12;

    /**
     * Recursively converts a value into scalars, arrays and nulls.
     */
    public static function jsonSafe(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(fn($item) => self::jsonSafe($item, $depth + 1), $value);
        }

        if ($value instanceof DateTimeInterface) {
            return self::date($value);
        }

        if ($value instanceof Money) {
            return [
                'amount' => $value->getAmount(),
                'currency' => $value->getCurrency()->getCode(),
            ];
        }

        if ($value instanceof Serializable) {
            return self::jsonSafe($value->serialize(), $depth + 1);
        }

        if ($value instanceof Arrayable) {
            return self::jsonSafe($value->toArray(), $depth + 1);
        }

        if ($value instanceof \Traversable) {
            return self::jsonSafe(iterator_to_array($value), $depth + 1);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_object($value)) {
            return self::jsonSafe(get_object_vars($value), $depth + 1);
        }

        // Resources and anything else that can't survive the trip.
        return null;
    }

    /**
     * ISO 8601, or null.
     */
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }

    /**
     * Drops null and empty-string entries so records stay readable — a category has no
     * author, so it shouldn't carry `"author": null`.
     */
    public static function compact(array $data): array
    {
        return array_filter($data, fn($value) => $value !== null && $value !== []);
    }
}
