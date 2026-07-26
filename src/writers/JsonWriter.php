<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;

/**
 * The canonical format: one pretty-printed JSON document holding metadata, schema and
 * every record. Lossless, and the reference every other writer is checked against.
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
        $json = json_encode(
            $this->document($context),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new \RuntimeException('Couldn’t encode the bundle as JSON: ' . json_last_error_msg());
        }

        return [$this->put($stagingDir, 'data/archive.json', $json)];
    }
}
