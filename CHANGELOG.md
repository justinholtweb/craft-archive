# Release Notes for Archive

## 5.0.0 - 2026-07-26

Initial release. Archive exports everything on a Craft site into a portable, platform-neutral
bundle, so the content can be migrated off Craft — or into anything else — without writing a
scraper.

The version number starts at 5.0.0 to track the major version of Craft it supports.

### Content

- Collectors for entries, categories, tags, global sets, assets, addresses and users.
- Records are one element in one site: records sharing a `uid` and differing by `site` are
  translations, which suits target platforms that don't share Craft's multi-site model.
- Relations are extracted alongside field values, so an importer can wire content up
  without walking every field.
- Portable field values with a stable `kind` — `richText`, `relation`, `blocks`, `money`
  and the rest — that an importer can switch on regardless of which plugin produced the
  field. Rich text has its Craft reference tags resolved to real URLs.
- Hyper, FreeLink, Google Maps and SEOmatic fields export as real data rather than the
  Craft element IDs they store internally.

### Formats

- JSON, NDJSON, XML, YAML and CSV. `manifest.json` is always JSON, whichever you pick.
- CSV gets one file per record type, a `relations.csv` join table, and the schema in its
  own directory, since it can't nest.

### Structure

- The site's structure travels with the content: sites, sections, entry types, field
  definitions, category and tag groups, volumes, filesystems, global sets, user groups with
  their permissions, routes, the installed plugin list and system settings.
- Filesystems are described by name and type only — never their settings, which is where
  cloud credentials live.

### Assets

- Files on local volumes are copied into the bundle; files on remote filesystems are
  referenced by URL so bundles stay small. Either can be overridden per export, and there's
  a size ceiling above which files are referenced rather than copied.

### Privacy

- User accounts are excluded unless explicitly allowed, and don't appear as an option until
  they are. Addresses belonging to a user account follow the same rule.
- Password hashes are never exported, under any setting.

### Scale

- Exports stream. Records are spooled to disk as they're collected and written back one at
  a time, so memory stays flat regardless of the size of the site.
- Queued exports, with a "run in the background" option on the export screen.
- Console commands: `archive/export`, `archive/bundles`, `archive/bundles/prune` and
  `archive/bundles/delete`.

### Extending

- Three event-driven registries — collectors, writers and value serializers — plus
  before/after export events. See `docs/EXTENDING.md`.
