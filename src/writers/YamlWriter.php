<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;
use Symfony\Component\Yaml\Yaml;

/**
 * The same document as the JSON writer, in YAML — for when someone needs to read or hand-
 * edit the content model before feeding it to the target platform.
 *
 * symfony/yaml ships with Craft, so this costs no extra dependency. Rather than dumping
 * one enormous array, each record is dumped on its own and indented into place, which
 * keeps memory flat and produces identical output.
 */
class YamlWriter extends BaseWriter
{
    /**
     * Nesting depth before Symfony collapses structures onto one line. Records nest about
     * six deep through Matrix blocks, so this stays well clear.
     */
    private const INLINE_DEPTH = 20;

    private const FLAGS = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;

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
        $path = 'data/archive.yaml';
        $handle = $this->open($stagingDir, $path);

        try {
            $this->emit($handle, "meta:\n");
            $this->emit($handle, $this->indent($this->dump($context->meta), 2) . "\n");

            if ($context->schema) {
                $this->emit($handle, "schema:\n");
                $this->emit($handle, $this->indent($this->dump($context->schema), 2) . "\n");
            }

            $this->emit($handle, "records:\n");

            foreach ($context->records->types() as $type) {
                if ($context->records->count($type) === 0) {
                    $this->emit($handle, "  $type: []\n");
                    continue;
                }

                $this->emit($handle, "  $type:\n");

                foreach ($context->records->each($type) as $record) {
                    $this->emit($handle, $this->listItem($this->dump($record), 4) . "\n");
                }
            }
        } finally {
            fclose($handle);
        }

        return [$path];
    }

    /**
     * Renders a dumped block as a YAML list item: the first line gets the dash, the rest
     * line up underneath it.
     */
    private function listItem(string $block, int $indent): string
    {
        $indented = $this->indent($block, $indent + 2);

        return substr_replace($indented, str_repeat(' ', $indent) . '- ', 0, $indent + 2);
    }

    private function dump(mixed $value): string
    {
        return rtrim(Yaml::dump($value, self::INLINE_DEPTH, 2, self::FLAGS), "\n");
    }
}
