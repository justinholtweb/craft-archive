<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;
use Symfony\Component\Yaml\Yaml;

/**
 * The same document as the JSON writer, in YAML — for when someone needs to read or hand-
 * edit the content model before feeding it to the target platform.
 *
 * symfony/yaml ships with Craft, so this costs no extra dependency.
 */
class YamlWriter extends BaseWriter
{
    /**
     * Nesting depth before Symfony collapses structures onto one line. Records nest about
     * six deep through Matrix blocks, so this stays well clear.
     */
    private const INLINE_DEPTH = 20;

    public static function format(): string
    {
        return 'yaml';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'YAML');
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $yaml = Yaml::dump(
            $this->document($context),
            self::INLINE_DEPTH,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );

        return [$this->put($stagingDir, 'data/archive.yaml', $yaml)];
    }
}
