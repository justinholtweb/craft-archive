<?php

namespace justinholtweb\archive\writers;

use Craft;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\BundleBuilder;
use RuntimeException;
use XMLWriter as XmlStream;

/**
 * The same document as the JSON writer, as XML.
 *
 * Built with PHP's streaming XMLWriter rather than a DOM tree, so the whole document never
 * exists in memory at once.
 *
 * Conventions, so a reader knows what it's looking at:
 *
 * - An object becomes one child element per key.
 * - A list becomes repeated `<item>` elements.
 * - A key that isn't a legal XML name becomes `<item key="…">`, which is what makes
 *   arbitrary field handles safe to emit.
 * - Null is `<foo nil="true"/>`; booleans are the strings `true` and `false`.
 * - Anything containing markup or newlines is wrapped in CDATA, so exported rich text
 *   survives intact.
 */
class XmlWriter extends BaseWriter
{
    public static function format(): string
    {
        return 'xml';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'XML');
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $path = 'data/archive.xml';
        $target = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        \craft\helpers\FileHelper::createDirectory(dirname($target));

        $xml = new XmlStream();

        if ($xml->openUri($target) === false) {
            throw new RuntimeException("Couldn’t open $path for writing.");
        }

        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('archive');
        $xml->writeAttribute('formatVersion', BundleBuilder::FORMAT_VERSION);

        $this->writeValue($xml, 'meta', $context->meta);

        if ($context->schema) {
            $this->writeValue($xml, 'schema', $context->schema);
        }

        $xml->startElement('records');

        foreach ($context->records->eachOfAll() as [$type, $record]) {
            $xml->startElement('record');
            $xml->writeAttribute('type', $type);
            $this->writeChildren($xml, $record);
            $xml->endElement();
        }

        $xml->endElement(); // records
        $xml->endElement(); // archive
        $xml->endDocument();
        $xml->flush();

        return [$path];
    }

    /**
     * Writes a value under a named element.
     */
    private function writeValue(XmlStream $xml, string $name, mixed $value): void
    {
        [$tag, $keyAttribute] = $this->tagFor($name);

        $xml->startElement($tag);

        if ($keyAttribute !== null) {
            $xml->writeAttribute('key', $keyAttribute);
        }

        if ($value === null) {
            $xml->writeAttribute('nil', 'true');
            $xml->endElement();
            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $this->writeValue($xml, 'item', $item);
                }
            } else {
                $this->writeChildren($xml, $value);
            }

            $xml->endElement();
            return;
        }

        $this->writeText($xml, $value);
        $xml->endElement();
    }

    /**
     * Writes every key of an associative array as a child element.
     */
    private function writeChildren(XmlStream $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->writeValue($xml, (string)$key, $value);
        }
    }

    /**
     * Writes a scalar, reaching for CDATA when the value contains markup or line breaks.
     */
    private function writeText(XmlStream $xml, mixed $value): void
    {
        if (is_bool($value)) {
            $xml->text($value ? 'true' : 'false');
            return;
        }

        $string = (string)$value;

        if (preg_match('/[<>&]|\R/', $string) === 1) {
            // CDATA can't contain the closing sequence, so split it across two sections.
            $xml->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $string));
            return;
        }

        $xml->text($string);
    }

    /**
     * The element name to use for a key, and the `key` attribute when the key can't be an
     * XML name in its own right.
     *
     * @return array{0: string, 1: string|null}
     */
    private function tagFor(string $name): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/', $name) === 1) {
            return [$name, null];
        }

        return ['item', $name];
    }
}
