# Changelog

## Unreleased

### Added
- A **Download** button in the QR code modal, which saves the QR as `qr-<slug>.png`.

### Changed
- Sidebar QR codes (both **Icon** and **Full** modes) now open in a modal rather than a new tab. Modifier-clicking still opens the image in a new tab, and the link falls back to its old behaviour if JavaScript is unavailable.

### Fixed
- The **Short Link** section is now placed correctly in the entry sidebar when another plugin adds its own fields.

## 1.2.0 - 2026-07-18

### Added
- **QR codes.** A short link's QR code can now be shown in the entry sidebar. Choose **None**, **Icon** (a small QR icon in the link row), or **Full** (the QR image) under the new **Sidebar** settings tab.
- **QR code style** setting (size, margin, foreground/background colour) applied to sidebar QR codes and `dubQr()`. Blank values fall back to defaults.
- `dubQr(entry)` Twig function for outputting a short link's QR code image in templates.
- **Click counts.** An optional read-only click count in the entry sidebar, toggled by **Show click count** in settings (off by default).

### Changed
- Plugin settings are now organised into **General** and **Sidebar** tabs.

### Fixed
- The short link destination now resolves from the entry's final URL, so it's correct on a new entry's first save and when the slug changes in the same save (previously it could be missing or lag a save behind).
- Pending link state is now tracked per entry and site, so multi-site propagation and nested `resave` saves no longer clobber each other.
- The **Short Link** section now appears for Singles (such as a home single) whose sidebar has no other fields.
- The **Open in Dub** admin icon no longer disappears after the cache is cleared.

## 1.1.0 - 2026-07-10

### Added
- **Sections** setting to choose which sections short links are enabled for. Supports a `DUB_SECTIONS` environment override (comma-separated section handles).
- Validation of the **Domain** setting against the domains in your Dub workspace.
- `dub/adopt` console command to adopt pre-existing Dub links into the plugin, matching them to entries by destination URL path. Supports `--dry-run` and `--rewrite` (fallback prefix remapping, e.g. `--rewrite="/areas-stages/=/venues/"`).

### Changed
- The **Domain** setting is now an autosuggest field that lists your available Dub domains and accepts an environment variable (e.g. `$DUB_DOMAIN`).
- The entry sidebar now shows the configured domain as the slug field label, and prompts to add an API key or select a domain when setup is incomplete. A domain must be selected before short links can be created (previously an unset domain fell back to Dub's default domain).

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