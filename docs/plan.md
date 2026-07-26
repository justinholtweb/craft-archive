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

### Phase 2 — full content coverage *(done)*
Collectors for categories, tags, global sets, assets, users (opt-in) and addresses, plus
a volumes filter on the export form.

`BaseCollector` gained two hooks: `isLocalized()`, so types with no per-site content —
users, addresses — are walked once and emit records with no site keys; and
`shouldCollect()`, for filtering a query can't express.

Two personal-data decisions are enforced in code rather than left to the operator:
`UserCollector::isAvailable()` returns false until user export is switched on, so users
don't even appear as an option; and `AddressCollector` holds back addresses owned by a
user under the same setting, warning once, while addresses owned by anything else travel
as ordinary content.

### Phase 3 — schema & settings export *(done)*
`SchemaExporter` covering sites, sections + entry types, field definitions (incl. settings
and options), category/tag groups, volumes, filesystems, user groups + permissions, global
set definitions, routes, the installed plugin list, and hand-picked system settings. This
is what makes a bundle re-modelable on the target platform rather than just a pile of rows.

Filesystems are exported by name and type only, never their settings — that's where cloud
credentials live. `system` is likewise a curated set rather than a dump of the general
config, which holds the security key.

### Phase 4 — remaining writers *(done)*
`NdjsonWriter`, `XmlWriter`, `YamlWriter` (symfony/yaml ships with Craft) and `CsvWriter`.

CSV needed real thought since it can't nest: one file per record type, a `relations.csv`
join table, the schema in its own `schema/` directory, dotted-key flattening, `|` for
multi-value cells, and JSON in the cell for anything that genuinely won't flatten. The
`Flattener` helper holds those rules so other tabular writers can reuse them.

### Phase 5 — scale *(done)*
Records are spooled to NDJSON scratch files by a `RecordStore` as they're collected, rather
than accumulating in memory, and every writer streams them back a record at a time — JSON
assembled by hand on disk, XML through PHP's streaming `XMLWriter`, YAML dumped per record
and indented into place, CSV walking the spool twice (cheap, since it's on disk) to work
out its column union.

Measured with 50,000 synthetic records: collection added **0 MB**, and each writer produced
its output — up to 83 MB — with no measurable memory growth. Peak stayed at 62 MB, which is
Craft's own bootstrap.

Also: an `ExportJob` with progress reporting and a "run in the background" option on the
export form, and console commands (`archive/export`, `archive/bundles`,
`archive/bundles/prune`, `archive/bundles/delete`).

### Phase 6 — field type coverage *(done)*
A `ValueSerializerInterface` registry, extensible by event, with implementations for Hyper,
FreeLink, Google Maps and SEOmatic. Each guards on `class_exists`, so they're inert when
that plugin isn't installed and nothing hard-depends on them.

These were the fields that exported as `raw` — technically present, practically useless:

| Field | Was | Now |
| --- | --- | --- |
| Hyper | `"linkValue": [12]` | `target` ref with uid, title and URL |
| FreeLink | `"value": null` for element links | same `link` shape as Hyper |
| Google Maps | address mixed with local row/element/site/field IDs | address parts + `lat`/`lng` |
| SEOmatic | 5KB bundle, images as Twig expressions | metadata + settings, images as asset refs |

Matrix and Table were already covered by the built-in serializer. Formie submissions and
Commerce products are element types rather than field types, so they belong to the
collector registry — worth adding, but not in this phase.

### Phase 7 — polish *(done)*
`docs/FORMAT.md` finalised, `docs/EXTENDING.md` covering all three registries and the
export events, README and CHANGELOG.

Two test suites, because they need different things. **Unit** (26 tests, no Craft): the
flattening rules, JSON-safe value conversion, and the record store — ordering, counts,
newline-bearing values surviving a line-based format, repeat traversal, path safety and
cleanup. **Integration**: real exports in every format against a live Craft, re-parsed and
compared against the JSON reference.

Testing the store this way meant taking Craft's `FileHelper` out of it, which was
unnecessary coupling for what is plain file I/O.

## Verification

Beyond the two test suites, these were checked against a live install with purpose-built
fixtures, because they're the parts a real migration leans on hardest and none of them were
exercised by the day-to-day test content:

- **Multi-site** — exporting two sites produces a record per site; translations share a uid
  and differ by `site`, `siteId` and `language`; the sites filter narrows the run.
- **Globals** — a global set with a site-translatable field produces one record per site,
  each with its own value. (With a non-translatable field Craft shares the value across
  sites, which is correct and worth knowing when reading a bundle.)
- **Remote assets** — with the volume repointed at a real S3 filesystem, assets are
  referenced by URL and no file is queued; with downloading switched on, an unreachable
  bucket produces a warning rather than a failed export.
- **Asset size limit** — files over the ceiling are referenced instead of copied, counted
  as skipped and warned about; a limit of 0 means no limit.
- **Retention** — `prune()` removes bundles past the age limit and trims to the count limit.

That pass found one real bug, since fixed: asset references are written during collection,
so `bundled: true` was a promise made before the file was on disk, and a copy failing later
left the bundle claiming to contain a file it didn't have. Files are now copied as each
asset is encountered, and the integration suite asserts that every reference claiming to be
bundled has its file.

## Non-goals

- Importing. Archive never writes to the Craft database.
- Being a backup tool. It exports *content*, not a restorable database dump.
- Rendered HTML pages. A static-mirror mode is a plausible future addition, not a v1 goal.
