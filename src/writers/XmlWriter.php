<?php

namespace justinholtweb\archive\writers;

use Craft;
use DOMDocument;
use DOMElement;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\BundleBuilder;
use RuntimeException;

/**
 * The same document as the JSON writer, as XML.
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
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElement('archive');
        $root->setAttribute('formatVersion', BundleBuilder::FORMAT_VERSION);
        $document->appendChild($root);

        $this->appendValue($document, $root, 'meta', $context->meta);

        if ($context->schema) {
            $this->appendValue($document, $root, 'schema', $context->schema);
        }

        $records = $document->createElement('records');
        $root->appendChild($records);

        foreach ($context->records as $type => $items) {
            foreach ($items as $record) {
                $element = $document->createElement('record');
                $element->setAttribute('type', $type);
                $records->appendChild($element);

                $this->appendChildren($document, $element, $record);
            }
        }

        $xml = $document->saveXML();

        if ($xml === false) {
            throw new RuntimeException('Couldn’t serialize the bundle as XML.');
        }

        return [$this->put($stagingDir, 'data/archive.xml', $xml)];
    }

    /**
     * Appends a value under a named element.
     */
    private function appendValue(DOMDocument $document, DOMElement $parent, string $name, mixed $value): void
    {
        [$tag, $keyAttribute] = $this->tagFor($name);

        $element = $document->createElement($tag);

        if ($keyAttribute !== null) {
            $element->setAttribute('key', $keyAttribute);
        }

        $parent->appendChild($element);

        if ($value === null) {
            $element->setAttribute('nil', 'true');
            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    $this->appendValue($document, $element, 'item', $item);
                }
            } else {
                $this->appendChildren($document, $element, $value);
            }
            return;
        }

        $element->appendChild($this->textNode($document, $value));
    }

    /**
     * Appends every key of an associative array as a child element.
     */
    private function appendChildren(DOMDocument $document, DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->appendValue($document, $parent, (string)$key, $value);
        }
    }

    /**
     * Turns a scalar into a text node, reaching for CDATA when the value contains markup
     * or line breaks.
     */
    private function textNode(DOMDocument $document, mixed $value): \DOMNode
    {
        if (is_bool($value)) {
            return $document->createTextNode($value ? 'true' : 'false');
        }

        $string = (string)$value;

        if (preg_match('/[<>&]|\R/', $string) === 1) {
            // CDATA can't contain the closing sequence, so split it across two sections.
            return $document->createCDATASection(str_replace(']]>', ']]]]><![CDATA[>', $string));
        }

        return $document->createTextNode($string);
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
