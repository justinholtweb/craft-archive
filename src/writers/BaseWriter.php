<?php

namespace justinholtweb\archive\writers;

use craft\helpers\FileHelper;
use justinholtweb\archive\models\ExportContext;
use RuntimeException;
use yii\base\BaseObject;

/**
 * Shared plumbing for writers: safe file creation and incremental writing.
 *
 * Writers are expected to stream. Records arrive from the context's record store one at a
 * time, and a writer should put each one on disk and move on rather than assembling a
 * whole document — that's what keeps a hundred-thousand-record export inside a sane
 * memory footprint.
 */
abstract class BaseWriter extends BaseObject implements WriterInterface
{
    /**
     * Opens a file inside the staging directory for writing, creating parent directories.
     *
     * @return resource
     */
    protected function open(string $stagingDir, string $relativePath)
    {
        $target = $this->target($stagingDir, $relativePath);

        FileHelper::createDirectory(dirname($target));

        $handle = fopen($target, 'w');

        if ($handle === false) {
            throw new RuntimeException("Couldn’t open $relativePath for writing.");
        }

        return $handle;
    }

    /**
     * Writes a whole file at once. For anything that scales with the size of the site,
     * prefer {@see open()} and write as you go.
     *
     * @param string $relativePath Bundle-relative, e.g. 'data/archive.json'.
     * @return string The relative path, for chaining into the manifest.
     */
    protected function put(string $stagingDir, string $relativePath, string $contents): string
    {
        $target = $this->target($stagingDir, $relativePath);

        FileHelper::createDirectory(dirname($target));

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException("Couldn’t write $relativePath into the bundle.");
        }

        return $relativePath;
    }

    /**
     * JSON-encodes one value the way every writer in Archive does.
     */
    protected function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($value, $flags);

        if ($json === false) {
            throw new RuntimeException('Couldn’t encode a bundle value as JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Indents every line of a block of text.
     */
    protected function indent(string $text, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);

        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }

    /**
     * Appends to an open handle.
     *
     * Named `emit` rather than `write` because {@see WriterInterface::write()} already
     * owns that name.
     *
     * @param resource $handle
     */
    protected function emit($handle, string $text): void
    {
        if (fwrite($handle, $text) === false) {
            throw new RuntimeException('Couldn’t write to the bundle.');
        }
    }

    private function target(string $stagingDir, string $relativePath): string
    {
        return $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
