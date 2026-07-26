<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;

/**
 * The canonical format: one JSON document holding metadata, schema and every record.
 * Lossless, and the reference every other writer is checked against.
 *
 * The document is assembled on disk a record at a time rather than encoded in one go, so
 * the size of the site doesn't decide the size of the process. Each record is still
 * pretty-printed individually, so the result stays readable.
 */
class JsonWriter extends BaseWriter
{
    public static function format(): string
    {
        return 'json';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'JSON');
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $path = 'data/archive.json';
        $handle = $this->open($stagingDir, $path);

        try {
            $this->emit($handle, "{\n");
            $this->emit($handle, '  "meta": ' . ltrim($this->indent($this->encode($context->meta, true), 2)) . ",\n");

            if ($context->schema) {
                $this->emit($handle, '  "schema": ' . ltrim($this->indent($this->encode($context->schema, true), 2)) . ",\n");
            }

            $this->emit($handle, "  \"records\": {\n");

            $types = $context->records->types();
            $lastType = array_key_last($types);

            foreach ($types as $index => $type) {
                $this->emit($handle, '    ' . $this->encode($type) . ': [');

                $empty = true;
                foreach ($context->records->each($type) as $record) {
                    $this->emit($handle, ($empty ? "\n" : ",\n") . $this->indent($this->encode($record, true), 6));
                    $empty = false;
                }

                $this->emit($handle, ($empty ? '' : "\n    ") . ']' . ($index === $lastType ? '' : ',') . "\n");
            }

            $this->emit($handle, "  }\n}\n");
        } finally {
            fclose($handle);
        }

        return [$path];
    }
}
