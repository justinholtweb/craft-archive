<?php

namespace justinholtweb\archive\writers;

use justinholtweb\archive\models\ExportContext;

/**
 * Writes a bundle's collected records out in one format.
 *
 * Register your own with {@see \justinholtweb\archive\services\WriterRegistry::EVENT_REGISTER_WRITERS}
 * to add a format — including a target-specific one such as WordPress WXR.
 */
interface WriterInterface
{
    /**
     * The format key, used in settings and on the export form — 'json', 'csv', …
     */
    public static function format(): string;

    /**
     * Human-readable name, shown on the export screen.
     */
    public static function label(): string;

    /**
     * Writes the data file(s) into the staging directory.
     *
     * @param string $stagingDir Absolute path to the bundle being assembled.
     * @return string[] Bundle-relative paths of the files written, for the manifest.
     */
    public function write(ExportContext $context, string $stagingDir): array;
}
