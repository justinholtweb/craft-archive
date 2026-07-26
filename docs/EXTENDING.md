# Extending Archive

Archive has three extension points, all event-driven, plus two lifecycle events. Between
them you can teach it about an element type it's never seen, add an output format —
including a target-specific one like WordPress WXR — or make your own field type's values
portable.

Register handlers from your plugin or module's `init()`.

## Collectors — new element types

A collector turns one element type into bundle records. Extend `BaseCollector` and you get
per-site iteration, batching, the shared record envelope, field serialization and relation
extraction for free; you supply the query and the type-specific keys.

```php
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use justinholtweb\archive\collectors\BaseCollector;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;

class ProductCollector extends BaseCollector
{
    public static function key(): string
    {
        // Used as the record type, and as a filename in CSV bundles.
        return 'products';
    }

    public static function label(): string
    {
        return 'Products';
    }

    public function isAvailable(): bool
    {
        // Collectors for optional plugins should say so, rather than blowing up.
        return class_exists(\craft\commerce\elements\Product::class);
    }

    protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface
    {
        return \craft\commerce\elements\Product::find()
            ->siteId($site->id)
            ->status($config->includeDisabled ? null : 'enabled');
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        return [
            'container' => ['productType' => $element->getType()->handle],
            'sku' => $element->defaultSku,
        ];
    }
}
```

```php
use justinholtweb\archive\services\CollectorRegistry;
use yii\base\Event;

Event::on(CollectorRegistry::class, CollectorRegistry::EVENT_REGISTER_COLLECTORS,
    function($event) {
        $event->collectors[] = new ProductCollector();
    });
```

Two hooks worth knowing:

- **`isLocalized()`** — return false for element types with no per-site content, like users
  and addresses. They're then walked once instead of once per site, and their records carry
  no `site`, `siteId` or `language` keys.
- **`shouldCollect()`** — a last filter for decisions a query can't express. Archive uses it
  to hold back addresses belonging to user accounts when users aren't being exported.

`query()` takes the config rather than the context so the same method can size up a run
before it starts, which is what gives a queued export its progress bar.

## Writers — new output formats

```php
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\writers\BaseWriter;

class WxrWriter extends BaseWriter
{
    public static function format(): string
    {
        return 'wxr';
    }

    public static function label(): string
    {
        return 'WordPress (WXR)';
    }

    public function write(ExportContext $context, string $stagingDir): array
    {
        $handle = $this->open($stagingDir, 'data/archive.wxr');

        foreach ($context->records->eachOfAll() as [$type, $record]) {
            $this->emit($handle, $this->item($type, $record));
        }

        fclose($handle);

        // Bundle-relative paths, for the manifest.
        return ['data/archive.wxr'];
    }
}
```

```php
use justinholtweb\archive\services\WriterRegistry;

Event::on(WriterRegistry::class, WriterRegistry::EVENT_REGISTER_WRITERS,
    function($event) {
        $event->writers[] = new WxrWriter();
    });
```

**Write as you go.** Records come from `$context->records`, a store backed by scratch files
on disk, and iterating it yields one record at a time. Building a whole document in memory
would undo that — it's the reason a 50,000-record export costs no more memory than a
50-record one. `$context->records->each($type)` walks one type, `eachOfAll()` walks
everything, and the store can be walked more than once if you need two passes (the CSV
writer does exactly that to work out its column union).

## Value serializers — new field types

Without one of these an unrecognised field still exports, through its own
`serializeValue()`, tagged `raw`. That's rarely much use: what most field types store is
Craft element IDs.

```php
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\archive\fields\ValueSerializerInterface;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\services\FieldSerializer;

class MyFieldSerializer implements ValueSerializerInterface
{
    public function supports(FieldInterface $field): bool
    {
        return class_exists(MyField::class) && $field instanceof MyField;
    }

    public function serialize(
        mixed $value,
        ElementInterface $element,
        FieldInterface $field,
        ExportContext $context,
    ): array {
        return [
            'kind' => FieldSerializer::KIND_LINK,
            'value' => [[
                'type' => 'entry',
                'text' => $value->label,
                // Turn IDs into refs. This is the whole job.
                'target' => RefHelper::ref($value->getElement(), $context),
            ]],
        ];
    }
}
```

```php
use justinholtweb\archive\services\FieldSerializer;

Event::on(FieldSerializer::class, FieldSerializer::EVENT_REGISTER_VALUE_SERIALIZERS,
    function($event) {
        $event->serializers[] = new MyFieldSerializer();
    });
```

The `kind` you return is what an importer switches on, so prefer an existing one —
`text`, `richText`, `number`, `boolean`, `date`, `option`, `options`, `relation`, `blocks`,
`table`, `link`, `color`, `money`, `address`, `seo` — over inventing your own.

Passing an asset through `RefHelper::ref()` also queues its file for the bundle, with
deduplication, so an asset referenced from three places is still only copied once.

## Export events

```php
use justinholtweb\archive\services\Export;

Event::on(Export::class, Export::EVENT_BEFORE_EXPORT, function($event) {
    // Amend the config, or stop the run.
    if (!$this->allowedRightNow()) {
        $event->isValid = false;
    }
});

Event::on(Export::class, Export::EVENT_AFTER_EXPORT, function($event) {
    // $event->bundle is the ledger row; $event->context has the counts and warnings.
    $this->notify($event->bundle->filename, $event->context->counts());
});
```

## Reporting problems

Anything that couldn't be represented losslessly should go through
`$context->warn('…')`. Warnings are deduplicated, stored against the bundle, shown on its
detail screen, and written into both `manifest.json` and the bundle's `README.txt` — so a
gap is visible to whoever opens the ZIP, not just to whoever ran the export.
