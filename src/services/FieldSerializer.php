<?php

namespace justinholtweb\archive\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\ContentBlock;
use craft\fields\data\ColorData;
use craft\fields\data\LinkData;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Time;
use DateTimeInterface;
use Illuminate\Support\Collection;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Turns Craft field values into portable ones.
 *
 * Every serialized value carries a `kind` — 'text', 'relation', 'blocks' and so on — which
 * is what an importer should switch on. `type` is the originating Craft field class, kept
 * for provenance, but nothing downstream needs to understand it.
 */
class FieldSerializer extends Component
{
    public const KIND_TEXT = 'text';
    public const KIND_RICH_TEXT = 'richText';
    public const KIND_NUMBER = 'number';
    public const KIND_BOOLEAN = 'boolean';
    public const KIND_DATE = 'date';
    public const KIND_OPTION = 'option';
    public const KIND_OPTIONS = 'options';
    public const KIND_RELATION = 'relation';
    public const KIND_BLOCKS = 'blocks';
    public const KIND_TABLE = 'table';
    public const KIND_LINK = 'link';
    public const KIND_COLOR = 'color';
    public const KIND_MONEY = 'money';
    public const KIND_RAW = 'raw';

    /**
     * How deep nested blocks may go before Archive stops descending.
     */
    private const MAX_BLOCK_DEPTH = 8;

    /**
     * Serializes every custom field on an element.
     *
     * @return array<string, array> Keyed by field handle.
     */
    public function serializeAll(ElementInterface $element, ExportContext $context, int $depth = 0): array
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $values = [];

        foreach ($layout->getCustomFields() as $field) {
            $values[$field->handle] = $this->serialize($element, $field, $context, $depth);
        }

        return $values;
    }

    /**
     * Serializes one field value.
     */
    public function serialize(ElementInterface $element, FieldInterface $field, ExportContext $context, int $depth = 0): array
    {
        try {
            $value = $element->getFieldValue($field->handle);
            [$kind, $portable] = $this->normalize($value, $element, $field, $context, $depth);
        } catch (Throwable $e) {
            $context->warn(Craft::t('archive', 'Couldn’t export the “{field}” field: {message}', [
                'field' => $field->handle,
                'message' => $e->getMessage(),
            ]));
            Craft::warning("Field {$field->handle} failed to serialize: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            [$kind, $portable] = [self::KIND_RAW, null];
        }

        return [
            'kind' => $kind,
            'type' => $field::class,
            'typeName' => $field::displayName(),
            'value' => $portable,
        ];
    }

    /**
     * Whether a field points at other elements, and so contributes to a record's
     * `relations` list as well as its `fields`.
     */
    public function isRelation(FieldInterface $field): bool
    {
        return $field instanceof BaseRelationField;
    }

    /**
     * The elements a relation field points at, as refs.
     *
     * @return list<array>
     */
    public function relationTargets(ElementInterface $element, FieldInterface $field, ExportContext $context): array
    {
        try {
            $value = $element->getFieldValue($field->handle);
        } catch (Throwable) {
            return [];
        }

        return array_map(
            fn(ElementInterface $target) => RefHelper::ref($target, $context),
            $this->relatedElements($value)
        );
    }

    /**
     * @return array{0: string, 1: mixed} The kind, and the portable value.
     */
    private function normalize(mixed $value, ElementInterface $element, FieldInterface $field, ExportContext $context, int $depth): array
    {
        if ($value === null) {
            return [$this->kindFor($field), null];
        }

        if ($field instanceof Matrix || $field instanceof ContentBlock) {
            return [self::KIND_BLOCKS, $this->blocks($value, $context, $depth)];
        }

        if ($field instanceof BaseRelationField) {
            $refs = array_map(
                fn(ElementInterface $target) => RefHelper::ref($target, $context),
                $this->relatedElements($value)
            );
            return [self::KIND_RELATION, $refs];
        }

        if ($field instanceof Table) {
            return [self::KIND_TABLE, ValueHelper::jsonSafe($value)];
        }

        if ($field instanceof Lightswitch) {
            return [self::KIND_BOOLEAN, (bool)$value];
        }

        if ($field instanceof Number || $field instanceof Range) {
            return [self::KIND_NUMBER, is_numeric($value) ? $value + 0 : null];
        }

        if ($field instanceof Money) {
            return [self::KIND_MONEY, ValueHelper::jsonSafe($value)];
        }

        if ($field instanceof Color || $value instanceof ColorData) {
            return [self::KIND_COLOR, $value instanceof ColorData ? $value->getHex() : (string)$value];
        }

        if ($field instanceof Date || $field instanceof Time || $value instanceof DateTimeInterface) {
            return [self::KIND_DATE, $value instanceof DateTimeInterface ? ValueHelper::date($value) : null];
        }

        if ($value instanceof MultiOptionsFieldData) {
            return [self::KIND_OPTIONS, array_map($this->option(...), iterator_to_array($value))];
        }

        if ($value instanceof OptionData) {
            return [self::KIND_OPTION, $this->option($value)];
        }

        if ($field instanceof BaseOptionsField) {
            return [self::KIND_OPTIONS, ValueHelper::jsonSafe($value)];
        }

        if ($value instanceof LinkData) {
            return [self::KIND_LINK, ValueHelper::compact([
                'type' => $value->getType(),
                'value' => $value->getValue(),
                'url' => $value->getUrl(),
                'label' => $value->getLabel(),
            ])];
        }

        if ($this->isRichText($field, $value)) {
            return [self::KIND_RICH_TEXT, $this->parseRefs((string)$value, $element)];
        }

        if (is_string($value)) {
            return [self::KIND_TEXT, $value];
        }

        if (is_bool($value)) {
            return [self::KIND_BOOLEAN, $value];
        }

        if (is_int($value) || is_float($value)) {
            return [self::KIND_NUMBER, $value];
        }

        // Anything Archive doesn't recognise still gets exported — through the field's own
        // serializer — it just arrives as opaque data rather than a known kind.
        $serialized = $field->serializeValue($value, $element);

        if ($serialized === null && is_object($value) && method_exists($value, '__toString')) {
            return [self::KIND_TEXT, (string)$value];
        }

        return [self::KIND_RAW, ValueHelper::jsonSafe($serialized)];
    }

    /**
     * Serializes nested entries (Matrix) or a content block into block records.
     *
     * @return list<array>
     */
    private function blocks(mixed $value, ExportContext $context, int $depth): array
    {
        if ($depth >= self::MAX_BLOCK_DEPTH) {
            $context->warn(Craft::t('archive', 'Stopped descending into nested blocks after {depth} levels.', [
                'depth' => self::MAX_BLOCK_DEPTH,
            ]));
            return [];
        }

        $blocks = [];

        foreach ($this->relatedElements($value) as $index => $block) {
            $entry = [
                'uid' => $block->uid,
                'id' => $block->id,
                'sortOrder' => $index + 1,
                'enabled' => $block->enabled,
            ];

            if ($block instanceof Entry) {
                $type = $block->getType();
                $entry['type'] = $type->handle;
                $entry['typeName'] = $type->name;
            } else {
                $entry['type'] = RefHelper::type($block);
            }

            if ($block->title !== null && $block->title !== '') {
                $entry['title'] = $block->title;
            }

            $entry['fields'] = $this->serializeAll($block, $context, $depth + 1);

            $blocks[] = $entry;
        }

        return $blocks;
    }

    /**
     * Element field values arrive as queries, collections, single elements or plain
     * arrays depending on the field. Flatten them all to a list of elements.
     *
     * @return list<ElementInterface>
     */
    private function relatedElements(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return $value->status(null)->all();
        }

        if ($value instanceof Collection) {
            return $value->all();
        }

        if ($value instanceof ElementInterface) {
            return [$value];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, fn($item) => $item instanceof ElementInterface));
        }

        if ($value instanceof \Traversable) {
            return array_values(array_filter(iterator_to_array($value), fn($item) => $item instanceof ElementInterface));
        }

        return [];
    }

    private function option(mixed $option): mixed
    {
        if (!$option instanceof OptionData) {
            return ValueHelper::jsonSafe($option);
        }

        // An unselected dropdown still hands back an OptionData, just an empty one. Report
        // that as null rather than as an empty object.
        if ($option->value === null || $option->value === '') {
            return null;
        }

        return ValueHelper::compact([
            'value' => $option->value,
            'label' => $option->label,
        ]);
    }

    /**
     * Rich text is anything backed by craftcms/html-field — CKEditor, Redactor and the
     * various forks — plus anything that declares itself as such.
     */
    private function isRichText(FieldInterface $field, mixed $value): bool
    {
        if (class_exists(\craft\htmlfield\HtmlFieldData::class) && $value instanceof \craft\htmlfield\HtmlFieldData) {
            return true;
        }

        return $this->isRichTextField($field);
    }

    /**
     * Whether a field produces rich text, judged from the field alone — which is all
     * there is to go on when its value is empty.
     */
    private function isRichTextField(FieldInterface $field): bool
    {
        if (class_exists(CkeditorField::class) && $field instanceof CkeditorField) {
            return true;
        }

        // Redactor and the other html-field forks all extend the same base.
        return class_exists(\craft\htmlfield\HtmlField::class) && $field instanceof \craft\htmlfield\HtmlField;
    }

    /**
     * Resolves Craft reference tags — `{asset:14:url}` and friends — to real values, so
     * rich text is readable outside Craft.
     */
    private function parseRefs(string $html, ElementInterface $element): string
    {
        if (!str_contains($html, '{')) {
            return $html;
        }

        try {
            return Craft::$app->getElements()->parseRefs($html, $element->siteId);
        } catch (Throwable) {
            return $html;
        }
    }

    /**
     * The kind a field would produce, used when its value is null so an importer still
     * knows what it's looking at.
     */
    private function kindFor(FieldInterface $field): string
    {
        return match (true) {
            $field instanceof Matrix, $field instanceof ContentBlock => self::KIND_BLOCKS,
            $field instanceof BaseRelationField => self::KIND_RELATION,
            $field instanceof Table => self::KIND_TABLE,
            $field instanceof Lightswitch => self::KIND_BOOLEAN,
            $field instanceof Number, $field instanceof Range => self::KIND_NUMBER,
            $field instanceof Money => self::KIND_MONEY,
            $field instanceof Color => self::KIND_COLOR,
            $field instanceof Date, $field instanceof Time => self::KIND_DATE,
            $field instanceof Checkboxes, $field instanceof MultiSelect => self::KIND_OPTIONS,
            $field instanceof BaseOptionsField => self::KIND_OPTION,
            $this->isRichTextField($field) => self::KIND_RICH_TEXT,
            default => self::KIND_TEXT,
        };
    }
}
