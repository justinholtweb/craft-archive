<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;

/**
 * Newline-delimited JSON: one self-describing object per line.
 *
 * Nothing has to hold the whole document in memory to read this — a consumer can process
 * a million records a line at a time — which is what makes it the format of choice for
 * large sites and for piping into other tools.
 */
class NdjsonWriter extends BaseWriter
{
    public static function format(): string
    {
        return 'ndjson';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'NDJSON (newline-delimited JSON)');
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $path = 'data/archive.ndjson';
        $handle = $this->open($stagingDir, $path);

        try {
            $this->line($handle, ['_type' => 'meta', 'meta' => $context->meta]);

            if ($context->schema) {
                $this->line($handle, ['_type' => 'schema', 'schema' => $context->schema]);
            }

            foreach ($context->records->eachOfAll() as [$type, $record]) {
                $this->line($handle, [
                    '_type' => 'record',
                    'recordType' => $type,
                    'record' => $record,
                ]);
            }
        } finally {
            fclose($handle);
        }

        return [$path];
    }

    /**
     * One line of NDJSON. Newlines inside values are escaped by json_encode, so a line
     * always stays a line.
     *
     * @param resource $handle
     */
    private function line($handle, array $data): void
    {
        $this->emit($handle, $this->encode($data) . "\n");
    }
}
