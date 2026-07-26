<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\models\Site;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportContext;
use Throwable;

/**
 * Collects entries — the bulk of most sites.
 *
 * Nested Matrix entries are deliberately not collected here: they belong to their owner's
 * record, and the field serializer writes them there as blocks.
 */
class EntryCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'entries';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Entries');
    }

    protected function query(ExportContext $context, Site $site): ?ElementQueryInterface
    {
        $handles = $context->config->sectionHandles ?: $this->allSectionHandles();

        if (!$handles) {
            return null;
        }

        return Entry::find()
            ->siteId($site->id)
            // Scoping to sections is also what keeps nested Matrix entries out: those have
            // no section of their own.
            ->section($handles)
            ->status($context->config->includeDisabled ? null : ['live', 'pending', 'expired'])
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->unique(false);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var Entry $element */
        $section = $element->getSection();
        $type = $element->getType();

        $attributes = [
            'container' => ValueHelper::compact([
                'section' => $section?->handle,
                'sectionName' => $section?->name,
                'sectionType' => $section?->type,
                'entryType' => $type->handle,
                'entryTypeName' => $type->name,
            ]),
            'postDate' => ValueHelper::date($element->postDate),
            'expiryDate' => ValueHelper::date($element->expiryDate),
        ];

        $authors = array_values(array_filter(array_map(
            RefHelper::userRef(...),
            $this->authors($element)
        )));

        if ($authors) {
            $attributes['author'] = $authors[0];

            // Craft 5 allows several authors per entry; keep the whole list when there is one.
            if (count($authors) > 1) {
                $attributes['authors'] = $authors;
            }
        }

        if ($section?->type === 'structure') {
            $attributes['level'] = $element->level;

            $parent = $this->parent($element);
            if ($parent !== null) {
                $attributes['parent'] = RefHelper::ref($parent, $context);
            }
        }

        return $attributes;
    }

    /**
     * @return \craft\elements\User[]
     */
    private function authors(Entry $entry): array
    {
        try {
            return $entry->getAuthors();
        } catch (Throwable) {
            return array_filter([$entry->getAuthor()]);
        }
    }

    private function parent(Entry $entry): ?ElementInterface
    {
        if (($entry->level ?? 1) <= 1) {
            return null;
        }

        try {
            return $entry->getParent();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function allSectionHandles(): array
    {
        return array_map(
            fn($section) => $section->handle,
            Craft::$app->getEntries()->getAllSections()
        );
    }
}
