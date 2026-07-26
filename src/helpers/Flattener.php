<?php

namespace justinholtweb\archive\helpers;

use justinholtweb\archive\services\FieldSerializer;

/**
 * Squashes a nested record into flat, dotted-key columns so it can live in a CSV row.
 *
 * This is lossy by nature — a spreadsheet has nowhere to put a Matrix block — so anything
 * that genuinely can't be flattened is JSON-encoded into its cell rather than dropped.
 */
abstract class Flattener
{
    /**
     * Separator for multi-value cells.
     */
    public const MULTI_VALUE_SEPARATOR = '|';

    /**
     * Flattens a record's envelope and attributes. `fields` and `relations` are handled
     * separately — fields get their own scalarization, relations get their own file.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public static function record(array $record): array
    {
        $flat = [];

        foreach ($record as $key => $value) {
            if ($key === 'fields' || $key === 'relations') {
                continue;
            }

            $flat += self::walk((string)$key, $value);
        }

        foreach ($record['fields'] ?? [] as $handle => $field) {
            $flat['fields.' . $handle] = self::fieldValue($field);
        }

        return $flat;
    }

    /**
     * Flattens an arbitrary nested array into dotted keys.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $flat += self::walk($path, $value);
        }

        return $flat;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    private static function walk(string $path, mixed $value): array
    {
        if ($value === null || is_scalar($value)) {
            return [$path => $value];
        }

        if (!is_array($value)) {
            return [$path => self::encode($value)];
        }

        if ($value === []) {
            return [$path => null];
        }

        if (array_is_list($value)) {
            // A list of scalars is readable as a joined cell; a list of structures isn't,
            // so that keeps its JSON.
            $scalars = array_filter($value, fn($item) => $item === null || is_scalar($item));

            if (count($scalars) === count($value)) {
                return [$path => implode(self::MULTI_VALUE_SEPARATOR, array_map(self::stringify(...), $value))];
            }

            return [$path => self::encode($value)];
        }

        $flat = [];
        foreach ($value as $key => $item) {
            $flat += self::walk($path . '.' . $key, $item);
        }

        return $flat;
    }

    /**
     * Reduces one serialized field to a single cell.
     */
    private static function fieldValue(array $field): string|int|float|bool|null
    {
        $value = $field['value'] ?? null;
        $kind = $field['kind'] ?? FieldSerializer::KIND_RAW;

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return match ($kind) {
            // Relations become a list of uids; the full targets live in relations.csv.
            FieldSerializer::KIND_RELATION => self::join(array_column($value, 'uid')),
            FieldSerializer::KIND_OPTION => $value['value'] ?? null,
            FieldSerializer::KIND_OPTIONS => self::join(array_column($value, 'value')),
            default => self::encode($value),
        };
    }

    /**
     * @param array<mixed> $values
     */
    private static function join(array $values): string
    {
        return implode(self::MULTI_VALUE_SEPARATOR, array_map(self::stringify(...), $values));
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    private static function encode(mixed $value): string
    {
        return (string)json_encode(
            ValueHelper::jsonSafe($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
