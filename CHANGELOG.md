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
- JSON master data file plus a `manifest.json` describing every bundle.
- Control panel export screen, bundle list with download and delete, and settings screen.
- `archive:export` and `archive:manage` permissions.
