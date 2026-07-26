<?php

namespace justinholtweb\archive\writers;

use Craft;
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

        foreach ($context->records as $type => $records) {
            // A type that matched nothing has no columns to write a header from, so it
            // would only produce an empty file. The manifest still records the zero.
            if (!$records) {
                continue;
            }

            $rows = array_map(Flattener::record(...), $records);
            $files[] = $this->putCsv($stagingDir, "data/csv/$type.csv", $rows);
        }

        $relations = $this->relationRows($context);
        if ($relations) {
            $files[] = $this->putCsv($stagingDir, 'data/csv/relations.csv', $relations);
        }

        foreach ($this->schemaFiles($context) as $path => $rows) {
            $files[] = $this->putCsv($stagingDir, $path, $rows);
        }

        return $files;
    }

    /**
     * Every relation in the bundle, as a join table: which record points at which, through
     * which field. This is the shape a relational importer actually wants.
     *
     * @return list<array<string, mixed>>
     */
    private function relationRows(ExportContext $context): array
    {
        $rows = [];

        foreach ($context->records as $type => $records) {
            foreach ($records as $record) {
                foreach ($record['relations'] ?? [] as $relation) {
                    foreach ($relation['targets'] ?? [] as $position => $target) {
                        $rows[] = [
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

        return $rows;
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
     * @param list<array<string, mixed>> $rows
     */
    private function putCsv(string $stagingDir, string $relativePath, array $rows): string
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        $columns = array_keys($columns);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException("Couldn’t build $relativePath.");
        }

        // An empty escape string gives plain RFC 4180 quoting, rather than PHP's
        // backslash escaping, which most CSV readers don't expect.
        fputcsv($handle, $columns, ',', '"', '');

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $line[] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }

            fputcsv($handle, $line, ',', '"', '');
        }

        rewind($handle);
        $contents = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->put($stagingDir, $relativePath, $contents);
    }
}
