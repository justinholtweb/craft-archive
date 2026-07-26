<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;
use RuntimeException;

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
        $lines = [];

        $lines[] = $this->line(['_type' => 'meta', 'meta' => $context->meta]);

        if ($context->schema) {
            $lines[] = $this->line(['_type' => 'schema', 'schema' => $context->schema]);
        }

        foreach ($context->records as $type => $records) {
            foreach ($records as $record) {
                $lines[] = $this->line([
                    '_type' => 'record',
                    'recordType' => $type,
                    'record' => $record,
                ]);
            }
        }

        return [$this->put($stagingDir, 'data/archive.ndjson', implode("\n", $lines) . "\n")];
    }

    /**
     * One line of NDJSON. Newlines inside values are escaped by json_encode, so a line
     * always stays a line.
     */
    private function line(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new RuntimeException('Couldn’t encode an NDJSON line: ' . json_last_error_msg());
        }

        return $json;
    }
}
