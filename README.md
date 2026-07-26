# Archive

**Export everything on a Craft site into a portable bundle, so the content can move to any
other platform.**

Archive packages entries, assets, relations, users and the site's own structure into a ZIP
containing a single master data file — JSON, XML, CSV, YAML or NDJSON — plus the asset
files themselves. Nothing in a bundle needs Craft to read it.

Free and MIT-licensed.

## Requirements

Craft CMS 5.3+, PHP 8.2+.

## Installation

```sh
composer require justinholtweb/craft-archive
php craft plugin/install archive
```

## Using it

**Archive → Export** in the control panel. Choose what to include, pick a format, and hit
*Create bundle*. The result lands under **Archive → Bundles**, where you can download or
delete it.

Every bundle contains:

```
manifest.json    what this bundle is, what's in it, and anything Archive couldn't
                 represent losslessly. Always JSON, whichever format you picked.
README.txt       the same thing in plain language.
data/            the master data file — metadata, the site's structure, and every
                 record.
assets/          asset files, laid out as assets/<volume>/<folder>/<filename>.
```

The site's structure — sites, sections, entry types, field definitions, groups, volumes,
routes and the installed plugin list — travels inside the data file under `schema`, so the
content model arrives with the content. Filesystems are described by name and type only:
their settings are never exported, because that's where cloud credentials live.

## Formats

| Format | Output | Good for |
| --- | --- | --- |
| **JSON** | `data/archive.json` | the canonical, lossless master file |
| **NDJSON** | `data/archive.ndjson` | huge sites — one object per line, nothing to hold in memory |
| **XML** | `data/archive.xml` | systems that speak XML; rich text is preserved in CDATA |
| **YAML** | `data/archive.yaml` | reading or hand-editing the model before an import |
| **CSV** | `data/csv/*.csv` + `schema/*.csv` | spreadsheets and simple importers |

CSV can't nest, so it gets one file per record type, a `relations.csv` join table, and the
schema in its own directory. Values that won't flatten are JSON-encoded into their cell.
[docs/FORMAT.md](docs/FORMAT.md) documents the conventions.

The full specification is in [docs/FORMAT.md](docs/FORMAT.md).

## Assets

Files on **local** volumes are copied into the bundle. Files on **remote** filesystems —
S3, DigitalOcean Spaces, Google Cloud Storage — are referenced by URL and not downloaded,
so a bundle for a site with tens of gigabytes of cloud media is still small enough to hand
someone. Every asset reference says which it is:

```json
{ "uid": "…", "filename": "hero.jpg", "url": "https://…/hero.jpg",
  "bundled": true, "path": "assets/images/hero.jpg" }
```

`bundled: false` means fetch it from `url`. You can override this per export if you do want
the bytes, and there's a size limit above which files are referenced rather than copied.

## What gets exported

Entries, categories, tags, global sets, assets, addresses — and users, if you allow them.
Each is a checkbox on the export screen, and the sections and volumes filters narrow down
entries and assets respectively.

Records for the same element in different sites share a `uid` and differ by `site`, so
translations stay linkable without nesting. Users and addresses have no per-site content in
Craft, so their records carry no site keys at all.

## Users

User accounts are **not** exported unless you turn them on in the settings, because a
bundle is a downloadable ZIP full of personal data. Until you do, users don't even appear
as an option on the export screen, and addresses belonging to a user account are held back
too — addresses owned by anything else, like an address field on an entry, travel as
ordinary content.

Password hashes are never exported, whatever that setting says.

## Extending it

Two registries, both event-driven:

```php
use justinholtweb\archive\services\CollectorRegistry;
use justinholtweb\archive\services\WriterRegistry;
use yii\base\Event;

// Teach Archive about your plugin's element type.
Event::on(CollectorRegistry::class, CollectorRegistry::EVENT_REGISTER_COLLECTORS,
    function($event) {
        $event->collectors[] = new MyElementCollector();
    });

// Add an output format — including a target-specific one, like WordPress WXR.
Event::on(WriterRegistry::class, WriterRegistry::EVENT_REGISTER_WRITERS,
    function($event) {
        $event->writers[] = new WxrWriter();
    });
```

`Export::EVENT_BEFORE_EXPORT` and `EVENT_AFTER_EXPORT` let you amend or cancel a run.

## Archive vs Transport

[Transport](https://github.com/justinholtweb/craft-transport) moves content *between Craft
environments* and is built to be re-imported by Craft, with dependency resolution, conflict
review and rollback. Archive goes the other way: one direction, no import, and an output
format designed for systems that aren't Craft.

## Licence

MIT.
