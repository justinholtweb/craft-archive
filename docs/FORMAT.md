# Archive Bundle Format (ABF) v1

A bundle is a ZIP file. Everything inside it is plain text or a copied asset file — there
is no Craft-specific encoding, and nothing in it needs Craft to read.

```
my-site-2026-07-26-140233.zip
├── manifest.json            always JSON, whatever the chosen format
├── README.txt               human-readable description of this bundle's layout
├── data/
│   └── archive.json         the master data file, in the chosen format
│       (or archive.ndjson / archive.xml / archive.yaml, or a csv/ directory)
├── schema/                  site structure — sections, fields, volumes, sites… (phase 3)
└── assets/
    └── <volumeHandle>/<path>/<filename>   copied files from local volumes only
```

## manifest.json

```json
{
  "archiveFormatVersion": "1.0",
  "generatedAt": "2026-07-26T14:02:33+00:00",
  "generator": {
    "plugin": "Archive",
    "pluginVersion": "1.0.0",
    "craftVersion": "5.10.0",
    "phpVersion": "8.2.20"
  },
  "source": {
    "systemName": "My Site",
    "primarySiteUrl": "https://example.com",
    "sites": [
      { "handle": "default", "name": "My Site", "language": "en-US",
        "primary": true, "baseUrl": "https://example.com" }
    ]
  },
  "format": "json",
  "dataFiles": ["data/archive.json"],
  "contents": { "entries": 128, "categories": 9 },
  "assets": { "included": 42, "referenced": 7, "skipped": 1, "bytes": 18452113 },
  "options": { "…the ExportConfig used…" },
  "warnings": ["…anything Archive could not fully represent…"]
}
```

`contents` is the authoritative record count per type. `warnings` is where a field type
with no serializer, an unreadable asset, or a skipped oversize file gets reported — an
empty array means the export was complete.

## The master data file

The JSON writer emits a single document:

```json
{
  "meta": { "…the same generator/source/format keys as the manifest…" },
  "schema": { "…site structure, when schema export is enabled…" },
  "records": {
    "entries": [ { … }, { … } ],
    "categories": [ { … } ]
  }
}
```

Other writers carry the same information in their own idiom:

- **NDJSON** — one JSON object per line: `{"_type":"meta",…}`, then one line per record.
- **XML** — `<archive><meta/><schema/><records><record type="entry">…</record></records></archive>`.
- **YAML** — the JSON document, in YAML.
- **CSV** — `data/csv/<type>.csv` per record type, plus `relations.csv` and `assets.csv`.
  Nested values are flattened with dotted keys (`fields.body`, `fields.gallery.0.url`);
  multi-value cells use `|` as the separator.

## Records

**A record is one element in one site.** Records from the same element share a `uid` and
differ by `site`, which is how translations stay linkable without nesting.

```json
{
  "uid": "3f2b9a1c-…",
  "id": 412,
  "type": "entry",
  "sourceClass": "craft\\elements\\Entry",
  "site": "default",
  "siteId": 1,
  "language": "en-US",

  "title": "Hello world",
  "slug": "hello-world",
  "uri": "news/hello-world",
  "url": "https://example.com/news/hello-world",
  "status": "live",
  "enabled": true,

  "container": {
    "section": "news",
    "sectionName": "News",
    "sectionType": "channel",
    "entryType": "article",
    "entryTypeName": "Article"
  },

  "author": { "uid": "…", "username": "jholt", "name": "Justin Holt" },
  "parent": { "uid": "…", "slug": "…", "title": "…" },
  "level": 1,
  "order": 3,

  "dateCreated": "2026-01-04T09:11:00+00:00",
  "dateUpdated": "2026-07-02T16:40:12+00:00",
  "postDate": "2026-01-04T09:00:00+00:00",
  "expiryDate": null,

  "fields": {
    "body": { "type": "ckeditor", "value": "<p>…</p>" },
    "featured": { "type": "lightswitch", "value": true },
    "heroImage": { "type": "assets", "value": [ { "…asset ref…" } ] }
  },

  "relations": [
    {
      "field": "relatedEntries",
      "fieldType": "entries",
      "targets": [
        { "uid": "…", "id": 9, "type": "entry", "title": "Another post",
          "url": "https://example.com/news/another-post" }
      ]
    }
  ]
}
```

Keys that don't apply to a type are omitted rather than set to `null` — a category has no
`author` or `postDate`, so those keys simply aren't there. `container` differs per type
(`{ "group": "topics" }` for categories, `{ "volume": "images" }` for assets, and so on).

### Field values

Every entry under `fields` is `{ "type": <craft field type handle>, "value": <portable value> }`.
The `type` is advisory — it tells an importer how the value was produced — while `value` is
always plain JSON-representable data:

| Craft field | `value` |
| --- | --- |
| Plain Text, Email, URL, Colour, Icon, Country | string |
| Number, Money | number (Money also carries `currency`) |
| Lightswitch | boolean |
| Date / Time | ISO 8601 string |
| Dropdown, Radio Buttons | `{ "value": "a", "label": "Option A" }` |
| Checkboxes, Multi-select | array of the above |
| CKEditor, Redactor, and other rich text | HTML string, with Craft reference tags already resolved to real URLs |
| Table | array of row objects keyed by column handle |
| Matrix | array of block objects: `{ uid, type, typeName, enabled, fields }`, recursively |
| Entries, Categories, Tags, Users, Assets | array of element refs (see below) |
| Anything else | whatever the field's own `serializeValue()` produces, JSON-encoded |

Rich text is exported with `parseRefs()` applied, so `{asset:14:url}` becomes the real URL
rather than a Craft-only token.

### Element refs

Any pointer to another element — a relation target, an author, a parent — uses the same
shape, so an importer only ever has to learn one:

```json
{ "uid": "…", "id": 14, "type": "asset", "title": "hero.jpg",
  "url": "https://example.com/uploads/hero.jpg" }
```

Asset refs carry extra keys, and are the one place a bundle distinguishes local from remote:

```json
{
  "uid": "…", "id": 14, "type": "asset", "title": "hero.jpg",
  "filename": "hero.jpg", "kind": "image", "mimeType": "image/jpeg",
  "size": 184213, "width": 2000, "height": 1333,
  "alt": "A hero image", "volume": "images",
  "url": "https://example.com/uploads/hero.jpg",

  "bundled": true,
  "path": "assets/images/hero.jpg"
}
```

- `bundled: true` — the bytes are in the ZIP at `path`. This is what happens for volumes on
  a local filesystem.
- `bundled: false` — the file lives on a remote filesystem (S3, Spaces, GCS…) and only the
  `url` is provided, deliberately, to keep the bundle small. Fetch it from `url` at import
  time. A bundle can be told to download remote files too, in which case they arrive with
  `bundled: true` like any other.

## Stability

`archiveFormatVersion` follows semver. Additive keys are a minor bump; anything that would
break a reader written against 1.x is a major bump.
