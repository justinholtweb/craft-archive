<?php

namespace justinholtweb\archive\writers;

use Craft;
use Generator;
use justinholtweb\archive\helpers\Flattener;
use justinholtweb\archive\models\ExportContext;
use RuntimeException;

/**
 * A set of CSV files rather than one document, because CSV can't nest.
 *
 *   data/csv/<type>.csv    one row per record, columns flattened to dotted keys
 *   data/csv/relations.csv one row per relation target, joining records by uid
 *   schema/<key>.csv       the site's structure, which has nowhere else to go in CSV
 *
 * Nested values that can't be flattened — Matrix blocks, table rows, anything `raw` —
 * are JSON-encoded into their cell. Multi-value cells use `|` as the separator.
 */
class CsvWriter extends BaseWriter
{
    public static function format(): string
    {
        return 'csv';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'CSV (one file per type)');
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $files = [];

        foreach ($context->records->types() as $type) {
            // A type that matched nothing has no columns to write a header from, so it
            // would only produce an empty file. The manifest still records the zero.
            if ($context->records->count($type) === 0) {
                continue;
            }

            $files[] = $this->streamCsv(
                $stagingDir,
                "data/csv/$type.csv",
                fn() => $this->recordRows($context, $type)
            );
        }

        if ($this->hasRelations($context)) {
            $files[] = $this->streamCsv(
                $stagingDir,
                'data/csv/relations.csv',
                fn() => $this->relationRows($context)
            );
        }

        foreach ($this->schemaFiles($context) as $path => $rows) {
            $files[] = $this->streamCsv($stagingDir, $path, fn() => yield from $rows);
        }

        return $files;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function recordRows(ExportContext $context, string $type): Generator
    {
        foreach ($context->records->each($type) as $record) {
            yield Flattener::record($record);
        }
    }

    /**
     * Whether anything in the bundle has an outgoing relation — checked before opening a
     * file, so an export with none doesn't leave an empty relations.csv behind.
     */
    private function hasRelations(ExportContext $context): bool
    {
        foreach ($context->records->eachOfAll() as [, $record]) {
            if (!empty($record['relations'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every relation in the bundle, as a join table: which record points at which, through
     * which field. This is the shape a relational importer actually wants.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function relationRows(ExportContext $context): Generator
    {
        foreach ($context->records->eachOfAll() as [$type, $record]) {
            foreach ($record['relations'] ?? [] as $relation) {
                foreach ($relation['targets'] ?? [] as $position => $target) {
                    yield [
                        'recordType' => $type,
                        'recordUid' => $record['uid'] ?? null,
                        'recordSite' => $record['site'] ?? null,
                        'field' => $relation['field'] ?? null,
                        'fieldType' => $relation['fieldType'] ?? null,
                        'position' => $position + 1,
                        'targetUid' => $target['uid'] ?? null,
                        'targetType' => $target['type'] ?? null,
                        'targetTitle' => $target['title'] ?? null,
                        'targetUrl' => $target['url'] ?? null,
                    ];
                }
            }
        }
    }

    /**
     * The schema, one CSV per section. Sections that are a map rather than a list — the
     * system settings — become a two-column key/value file.
     *
     * @return array<string, list<array<string, mixed>>> Keyed by bundle-relative path.
     */
    private function schemaFiles(ExportContext $context): array
    {
        $files = [];

        foreach ($context->schema as $key => $section) {
            if (!is_array($section) || $section === []) {
                continue;
            }

            if (!array_is_list($section)) {
                $files["schema/$key.csv"] = array_map(
                    fn($name, $value) => ['setting' => $name, 'value' => $value],
                    array_keys(Flattener::flatten($section)),
                    array_values(Flattener::flatten($section))
                );
                continue;
            }

            $files["schema/$key.csv"] = array_map(
                fn($row) => is_array($row) ? Flattener::flatten($row) : ['value' => $row],
                $section
            );
        }

        return $files;
    }

    /**
     * Writes rows as CSV, using the union of every row's keys as the header so a column
     * one record uses and another doesn't still appears.
     *
     * Because the header can't be known until every row has been seen, this walks the rows
     * twice — which is cheap, since they're already spooled on disk. Only one row is in
     * memory at a time either way.
     *
     * @param callable(): iterable<array<string, mixed>> $rows Called once per pass.
     */
    private function streamCsv(string $stagingDir, string $relativePath, callable $rows): string
    {
        $columns = [];

        foreach ($rows() as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        $columns = array_keys($columns);
        $handle = $this->open($stagingDir, $relativePath);

        try {
            $this->putRow($handle, $columns);

            foreach ($rows() as $row) {
                $line = [];

                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $line[] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                }

                $this->putRow($handle, $line);
            }
        } finally {
            fclose($handle);
        }

        return $relativePath;
    }

    /**
     * @param resource $handle
     * @param list<mixed> $values
     */
    private function putRow($handle, array $values): void
    {
        // An empty escape string gives plain RFC 4180 quoting, rather than PHP's
        // backslash escaping, which most CSV readers don't expect.
        if (fputcsv($handle, $values, ',', '"', '') === false) {
            throw new RuntimeException('Couldn’t write a CSV row.');
        }
    }
}
