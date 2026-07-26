# Release Notes for Archive

## Unreleased

### Added

- Initial release: export Craft content into portable, platform-neutral ZIP bundles.
- Entry export with per-site records, relations, and portable field values.
- Collectors for categories, tags, global sets, assets, users and addresses.
- Asset files from local volumes are copied into bundles; remote volumes are referenced
  by URL. Assets are collected in their own right, not just when something links to them.
- A volumes filter on the export form, alongside the existing sections filter.
- User accounts are excluded unless explicitly allowed, and password hashes are never
  exported. Addresses belonging to a user account follow the same rule.
- Five output formats: JSON, NDJSON, XML, YAML and CSV.
- Schema export covering sites, sections, entry types, fields, category and tag groups,
  volumes, filesystems, global sets, user groups with permissions, routes, the installed
  plugin list and system settings. Filesystem credentials are never included.
- `manifest.json` lists every asset file that made it into the bundle.
- Exports stream: records are spooled to disk as they're collected and written back one at
  a time, so memory stays flat regardless of how big the site is.
- Queued exports, with a "run in the background" option on the export screen.
- Console commands: `archive/export`, `archive/bundles`, `archive/bundles/prune` and
  `archive/bundles/delete`.
- Portable values for Hyper, FreeLink, Google Maps and SEOmatic fields, which previously
  exported as opaque data containing Craft element IDs. Element links now carry a target
  reference, and SEOmatic's images resolve to asset references.
- JSON master data file plus a `manifest.json` describing every bundle.
- Control panel export screen, bundle list with download and delete, and settings screen.
- `archive:export` and `archive:manage` permissions.
