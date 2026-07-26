# Archive — build plan

**Archive** is a free Craft CMS 5 plugin that exports everything on a site into a
self-contained, platform-neutral bundle so the content can be migrated *off* Craft
(or into any other system) without hand-writing a scraper.

Not to be confused with [Transport](https://github.com/justinholtweb/craft-transport),
which moves content *between Craft environments* and is designed to be re-imported by
Craft. Archive is one-way and target-agnostic: its output is meant to be read by
something that isn't Craft.

- Package: `justinholtweb/craft-archive`
- Namespace: `justinholtweb\archive`
- Handle: `archive`
- Licence: MIT (free, no licensing/edition gate)
- Requires: PHP `^8.2`, `craftcms/cms ^5.3.0`, `ext-zip`, `ext-dom`

## Product decisions

| Decision | Choice | Why |
| --- | --- | --- |
| Output formats | JSON, NDJSON, XML, YAML, CSV | JSON is the canonical/lossless master; NDJSON streams; CSV is the flat multi-file set for spreadsheets and simple importers; XML/YAML for systems that prefer them. |
| Platform presets | None | One well-documented neutral schema plus a pluggable writer registry. Anyone can add a WordPress WXR or Contentful writer in their own module without Archive taking on that maintenance. |
| Users | Opt-in, never included by default | A bundle is a downloadable ZIP full of PII. Password hashes are **never** exported under any setting. |
| Remote assets | Referenced, not downloaded | Volumes on non-local filesystems (S3, Spaces, GCS…) contribute a URL + metadata only, keeping bundles slim. A setting can opt into pulling the bytes. |
| Multi-site | One record per element **per site** | Target platforms rarely share Craft's "one element, many sites" model. Records that belong to the same element share a `uid`, so translations stay linkable. |

## Architecture

```
Export service
  ├── ExportConfig            what to include (types, sites, sections, statuses, format, assets)
  ├── CollectorRegistry       element type → Collector       (extensible via event)
  │     └── Collector         builds a neutral record array per element/site
  │           └── FieldSerializer   field value → portable value + relation edges
  ├── SchemaExporter          sections, entry types, fields, groups, volumes, sites, settings
  ├── AssetBundler            local files copied in; remote files referenced
  ├── WriterRegistry          format → Writer                (extensible via event)
  │     └── Writer            neutral records → archive.json / .xml / .csv / …
  └── BundleBuilder           staging dir → manifest.json → ZIP → archive_bundles row
```

Everything crosses those two registries, so a third-party plugin can add support for its
own element type or its own output format without touching Archive.

## Phases

### Phase 1 — foundation *(done)*
Repo scaffold, `Plugin`, `Settings`, install migration (`archive_bundles`), permissions,
CP nav. Neutral record format, `FieldSerializer` covering core field types, `EntryCollector`,
`JsonWriter`, `BundleBuilder` (staging → manifest → ZIP), `Export` service, CP export form,
bundles list with download/delete, settings screen.

### Phase 2 — full content coverage
Collectors for categories, tags, global sets, assets, users (opt-in), addresses.
`AssetBundler`: copy local volume files into `assets/<volume>/<path>`, reference remote
volumes by URL, honour a max-file-size skip, dedupe, record every file in the manifest.

### Phase 3 — schema & settings export
`SchemaExporter` writing `schema/` — sites, sections + entry types, field definitions
(incl. settings and options), category/tag groups, volumes + filesystems, user groups and
permissions, global set definitions, routes, and general site settings. This is what makes
a bundle re-modelable on the target platform rather than just a pile of rows.

### Phase 4 — remaining writers
`NdjsonWriter`, `XmlWriter`, `YamlWriter` (symfony/yaml ships with Craft), `CsvWriter`
(one file per record type + `relations.csv` + `assets.csv`, with a documented flattening
convention for nested values).

### Phase 5 — scale
Queue jobs for export, batched element iteration, streaming writers so a 100k-entry site
doesn't exhaust memory, progress reporting, console commands
(`archive/export`, `archive/bundles/list`, `archive/bundles/prune`).

### Phase 6 — field type coverage
Value serializers for Matrix (recursive), Table, Hyper, Freelink, Google Maps, SEOMatic,
Formie submissions, Commerce products/variants/orders — registered conditionally on
`class_exists` so nothing hard-depends on them.

### Phase 7 — polish
`docs/FORMAT.md` finalised, `docs/EXTENDING.md` for the two registries, README, CHANGELOG,
unit tests for the serializers and writers, integration test that round-trips a real export.

## Non-goals

- Importing. Archive never writes to the Craft database.
- Being a backup tool. It exports *content*, not a restorable database dump.
- Rendered HTML pages. A static-mirror mode is a plausible future addition, not a v1 goal.
