# Tests

Two suites, because they need very different things.

## Unit — `vendor/bin/phpunit`

Plain PHP. No Craft application, no database, no fixtures. These cover the parts that are
pure logic and easy to get subtly wrong:

- **`FlattenerTest`** — the rules that decide what a CSV bundle looks like: dotted columns,
  `|`-joined multi-values, JSON in the cell for anything that won't flatten, relations
  collapsing to target uids.
- **`ValueHelperTest`** — turning whatever a field hands over into something a writer can
  emit, including dates, stringable objects, resources and self-referencing structures.
- **`RecordStoreTest`** — the spool that keeps exports flat in memory: ordering, per-type
  counts, values containing newlines surviving a line-based format, being walked more than
  once, type names not escaping the directory, and cleanup.

```sh
composer install
vendor/bin/phpunit
```

`RecordStore` is deliberately free of any dependency on a running Craft, which is what lets
it be tested this way.

## Integration — `tests/integration/export-roundtrip.php`

Runs real exports against a real Craft install with real content, in every registered
format, then re-parses each one and compares it against the JSON reference. It checks that
NDJSON and YAML records are identical to JSON, that the XML is well-formed with the right
record counts, that every CSV file has one row per record with no ragged rows, that empty
types are omitted rather than written as empty files, and that every bundle carries the
asset files its manifest claims.

```sh
CRAFT_BASE_PATH=/path/to/craft-project php tests/integration/export-roundtrip.php
```

The project needs Archive installed. The script cleans up the bundles it creates and exits
non-zero on failure, so it can be wired into CI.

### A warning from experience

Don't validate CSV or NDJSON output by splitting on newlines. CSV cells contain them — any
record with rich text has one — and so does exported HTML. Three "failures" during
development turned out to be the test harness, not the plugin. Use `fgetcsv` and a real
parser.
