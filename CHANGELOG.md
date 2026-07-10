# Changelog

## 1.1.0 - Unreleased

### Added
- `dub/adopt` console command to adopt pre-existing Dub links into the plugin, matching them to entries by destination URL path. Supports `--dry-run` and `--rewrite` (fallback prefix remapping, e.g. `--rewrite="/areas-stages/=/venues/"`).

## 1.0.3 - 2026-03-23

### Added
- `dubLink(entry)` Twig function for outputting short links in templates.

## 1.0.1 - 2026-03-23

### Changed
- Open in Dub link is now only visible to admins.

## 1.0.0 - 2026-03-20

### Added
- Automatically create and update Dub short links when entries are saved.
- Delete short links in Dub when entries are deleted or slug is removed.
- Archive short links in Dub when entries are disabled.
- Custom short link slug support via the entry editor sidebar.
- API key configuration with environment variable support.
- Domain selection in plugin settings.