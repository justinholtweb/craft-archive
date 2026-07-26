<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Shared machinery for collectors: per-site iteration, batching, and the record envelope
 * every element type has in common.
 *
 * Subclasses supply the query and any type-specific keys.
 */
abstract class BaseCollector extends Component implements CollectorInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether this element type has per-site content.
     *
     * Localized types are walked once per exported site and produce one record per site.
     * Types that don't vary by site — users, addresses — are walked once, and their
     * records carry no site keys at all.
     */
    protected function isLocalized(): bool
    {
        return true;
    }

    /**
     * The query this collector walks, for one site.
     *
     * Takes the config rather than the whole context so it can also be used to size up a
     * run before collecting anything — see {@see estimate()}.
     */
    abstract protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface;

    /**
     * Roughly how many records this collector will produce, for progress reporting. Counts
     * rather than collects, so it's cheap.
     */
    public function estimate(ExportConfig $config): int
    {
        $total = 0;

        foreach ($this->sites($config) as $site) {
            $query = $this->query($config, $site);

            if ($query === null) {
                continue;
            }

            $count = (int)$query->count();
            $total += $config->limit !== null ? min($count, $config->limit) : $count;
        }

        return $total;
    }

    /**
     * A last filter, for decisions a query can't express — such as holding back addresses
     * that belong to user accounts when users aren't being exported.
     */
    protected function shouldCollect(ElementInterface $element, ExportContext $context): bool
    {
        return true;
    }

    /**
     * Type-specific keys — `container`, `author`, dates and so on. Merged into the record
     * after the shared envelope.
     */
    abstract protected function attributes(ElementInterface $element, ExportContext $context): array;

    public function collect(ExportContext $context): void
    {
        foreach ($this->sites($context->config) as $site) {
            $query = $this->query($context->config, $site);

            if ($query === null) {
                continue;
            }

            foreach ($this->each($query, $context) as $element) {
                try {
                    if (!$this->shouldCollect($element, $context)) {
                        continue;
                    }

                    $context->addRecord(static::key(), $this->record($element, $context, $site));
                } catch (Throwable $e) {
                    $context->warn(Craft::t('archive', 'Skipped {type} {id}: {message}', [
                        'type' => static::key(),
                        'id' => $element->id,
                        'message' => $e->getMessage(),
                    ]));
                    Craft::warning(sprintf(
                        'Skipped %s %s: %s',
                        static::key(),
                        $element->id,
                        $e->getMessage()
                    ), Plugin::LOG_CATEGORY);
                }
            }
        }
    }

    /**
     * The sites to walk. Non-localized types are walked exactly once.
     *
     * @return Site[]
     */
    protected function sites(ExportConfig $config): array
    {
        $sites = $config->getSites();

        if ($this->isLocalized()) {
            return $sites;
        }

        return [reset($sites) ?: Craft::$app->getSites()->getPrimarySite()];
    }

    /**
     * Builds one record: the shared envelope, the subclass's attributes, then fields and
     * relations.
     */
    protected function record(ElementInterface $element, ExportContext $context, Site $site): array
    {
        $record = [
            'uid' => $element->uid,
            'id' => $element->id,
            'type' => RefHelper::type($element),
            'sourceClass' => $element::class,
        ];

        if ($this->isLocalized()) {
            $record['site'] = $site->handle;
            $record['siteId'] = $site->id;
            $record['language'] = $site->language;
        }

        $record += [
            'title' => $element->title,
            'slug' => $element->slug,
            'uri' => $element->uri,
            'url' => $element->getUrl(),
            'status' => $element->getStatus(),
            'enabled' => $element->enabled,
            'dateCreated' => ValueHelper::date($element->dateCreated),
            'dateUpdated' => ValueHelper::date($element->dateUpdated),
        ];

        $record = array_merge($record, $this->attributes($element, $context));

        $record['fields'] = Plugin::getInstance()->fields->serializeAll($element, $context);
        $record['relations'] = $this->relations($element, $context);

        return ValueHelper::compact($record);
    }

    /**
     * The element's outgoing relations, pulled out of its fields so an importer can wire
     * things up without walking every field value.
     *
     * @return list<array>
     */
    protected function relations(ElementInterface $element, ExportContext $context): array
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $serializer = Plugin::getInstance()->fields;
        $relations = [];

        foreach ($layout->getCustomFields() as $field) {
            if (!$serializer->isRelation($field)) {
                continue;
            }

            $targets = $serializer->relationTargets($element, $field, $context);

            if (!$targets) {
                continue;
            }

            $relations[] = [
                'field' => $field->handle,
                'fieldType' => $field::class,
                'targets' => $targets,
            ];
        }

        return $relations;
    }

    /**
     * Walks a query in batches so a large site doesn't have to fit in memory all at once.
     *
     * Offset paging with an explicit order, rather than `each()`, because element queries
     * re-run their sub-query per batch and need a stable sort to page reliably.
     *
     * @return iterable<ElementInterface>
     */
    protected function each(ElementQueryInterface $query, ExportContext $context): iterable
    {
        $batchSize = max(1, Plugin::getInstance()->getSettings()->batchSize);
        $limit = $context->config->limit;
        $collected = 0;
        $offset = 0;

        while (true) {
            $size = $limit !== null ? min($batchSize, $limit - $collected) : $batchSize;

            if ($size < 1) {
                return;
            }

            /** @var ElementQueryInterface $batchQuery */
            $batchQuery = (clone $query)
                ->orderBy(['elements.id' => SORT_ASC])
                ->offset($offset)
                ->limit($size);

            $elements = $batchQuery->all();

            if (!$elements) {
                return;
            }

            foreach ($elements as $element) {
                yield $element;
                $collected++;
            }

            if (count($elements) < $size) {
                return;
            }

            $offset += $size;
        }
    }
}
