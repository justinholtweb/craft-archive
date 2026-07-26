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
└── assets/
    └── <volumeHandle>/<path>/<filename>   copied files from local volumes only
```

The site's structure — sites, sections, entry types, field definitions, category and tag
groups, volumes, filesystems, global sets, user groups with their permissions, routes, the
installed plugin list, and hand-picked system settings — lives inside the master data file
under `schema`. Formats that can't nest, like CSV, put it in a `schema/` directory instead.

Filesystems are described by name and type only. Their settings are never exported: that's
where an S3 or Spaces filesystem keeps its access key and secret, and a bundle is a file
people email each other.

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
  "assets": {
    "included": 42, "referenced": 7, "skipped": 1, "bytes": 18452113,
    "files": ["assets/images/hero.jpg", "…"]
  },
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

Other writers carry the same information in their own idiom.

### NDJSON — `data/archive.ndjson`

One self-describing JSON object per line, so nothing has to hold the document in memory:

```
{"_type":"meta","meta":{…}}
{"_type":"schema","schema":{…}}
{"_type":"record","recordType":"entries","record":{…}}
```

### YAML — `data/archive.yaml`

The JSON document, in YAML. Structurally identical.

### XML — `data/archive.xml`

```xml
<archive formatVersion="1.0">
  <meta>…</meta>
  <schema>…</schema>
  <records>
    <record type="entries">…</record>
  </records>
</archive>
```

- An object becomes one child element per key; a list becomes repeated `<item>` elements.
- A key that isn't a legal XML name becomes `<item key="…">`, which is what makes arbitrary
  field handles safe to emit.
- Null is `<foo nil="true"/>`; booleans are the strings `true` and `false`.
- Values containing markup or newlines are wrapped in CDATA, so rich text survives intact.

### CSV — `data/csv/` and `schema/`

CSV can't nest, so a bundle in this format is a set of files rather than one document:

```
data/csv/<type>.csv     one row per record, columns flattened to dotted keys
data/csv/relations.csv  one row per relation target, joining records by uid
schema/<key>.csv        the site's structure, which has nowhere else to go
```

- Nested keys flatten to dotted columns: `container.section`, `author.username`,
  `file.filename`.
- The header is the union of every row's keys, so a column one record uses and another
  doesn't still appears.
- Multi-value cells use `|` as the separator.
- Each field becomes a single `fields.<handle>` column. Relations collapse to a list of
  target uids — the full targets are in `relations.csv`; an `option` collapses to its
  value; anything that genuinely can't flatten (Matrix blocks, table rows, `raw`) is
  JSON-encoded into its cell.
- A record type that matched nothing is skipped rather than written as an empty file. The
  manifest still records the zero.
- Quoting is plain RFC 4180 — no backslash escaping.

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
    "body": {
      "kind": "richText",
      "type": "craft\\ckeditor\\Field",
      "typeName": "CKEditor",
      "value": "<p>…</p>"
    },
    "featured": {
      "kind": "boolean",
      "type": "craft\\fields\\Lightswitch",
      "typeName": "Lightswitch",
      "value": true
    }
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
`author` or `postDate`, so those keys simply aren't there.

### Per type

`container` says where a record lived, and differs by type:

| `type` | `container` | Also carries |
| --- | --- | --- |
| `entry` | `{ section, sectionName, sectionType, entryType, entryTypeName }` | `author`, `authors`, `postDate`, `expiryDate`; `level` + `parent` in structures |
| `category` | `{ group, groupName }` | `level`, `parent` |
| `tag` | `{ group, groupName }` | — |
| `globalSet` | `{ handle, name }` | — |
| `asset` | `{ volume, volumeName, folderPath }` | `file` (see below) |
| `user` | — | `username`, `email`, `fullName`, `firstName`, `lastName`, `admin`, `pending`, `locked`, `suspended`, `lastLoginDate`, `groups`, `preferences`, `photo` |
| `address` | — | `label`, `fullName`, `organization`, `countryCode`, `administrativeArea`, `locality`, `dependentLocality`, `postalCode`, `sortingCode`, `addressLine1`–`3`, `latitude`, `longitude`, `owner` |

An asset record's `file` key holds the same file metadata an asset ref does — `filename`,
`kind`, `mimeType`, `size`, `width`, `height`, `alt`, `bundled`, `path` — so a record and a
reference to it describe the file identically.

**User records never contain a password hash**, under any setting. Users are also excluded
from bundles entirely unless the site's operator has explicitly allowed it, as are
addresses belonging to a user account.

### Records without a site

`user` and `address` records carry no `site`, `siteId` or `language`, because those element
types have no per-site content in Craft. Every other type has one record per site.

### Field values

Every entry under `fields` has four keys:

- **`kind`** — what the value *is*, in Archive's own vocabulary. **This is what an importer
  should switch on**, and it's stable across whichever plugin produced the field.
- **`type`** — the originating Craft field class, kept for provenance.
- **`typeName`** — that field type's display name.
- **`value`** — plain, JSON-representable data.

| `kind` | Craft fields | `value` |
| --- | --- | --- |
| `text` | Plain Text, Email, URL, Country | string |
| `richText` | CKEditor, Redactor, other html-field types | HTML string, reference tags already resolved |
| `number` | Number, Range | number |
| `boolean` | Lightswitch | boolean |
| `date` | Date, Time | ISO 8601 string |
| `option` | Dropdown, Radio Buttons, Button Group | `{ "value": "a", "label": "Option A" }`, or null when nothing is selected |
| `options` | Checkboxes, Multi-select | array of the above |
| `relation` | Entries, Categories, Tags, Users, Assets | array of element refs (see below) |
| `blocks` | Matrix, Content Block | array of `{ uid, type, typeName, sortOrder, enabled, fields }`, recursively |
| `table` | Table | array of row objects keyed by column handle |
| `link` | Link | `{ type, value, url, label }` |
| `color` | Colour | hex string |
| `money` | Money | `{ amount, currency }` |
| `raw` | anything Archive doesn't recognise | whatever the field's own `serializeValue()` produces |

`raw` is the honest fallback: the value still travels, it just arrives as opaque data
whose shape is the originating plugin's business. Field types from third-party plugins
land here until Archive learns them.

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
