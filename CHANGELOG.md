# Changelog

## Unreleased

### Added
- A `dub/check` console command, which verifies that every recorded short link still exists at Dub and still matches what Craft recorded. Add `--fix` to recreate links deleted from the Dub dashboard, and to reconcile ones edited there — pushing Craft's destination back, and adopting a slug renamed at Dub rather than reversing it. It exits non-zero when anything needs attention, so it can be run from cron or CI.

### Changed
- Saving an entry whose short link hasn't moved no longer calls the Dub API. Resaving a section is now one request per link that actually changed, rather than one per entry per site.
- `dub/adopt` now identifies links the plugin already created from the `externalId` stamped on them, rather than matching them by destination like any other link. Where several entries shared a path, those links were reported as ambiguous when they were simply already accounted for.
- `dub/adopt` can now adopt a link that points at a site's homepage. Homepages have no path, so they were skipped entirely when building the candidate list — a homepage link could never be adopted, and was reported as unmatched even when the plugin was already managing it.
- `dub/adopt` now matches links on the host and path of their destination together, rather than the path alone, so sites on separate domains or subdomains are adopted correctly even when they share a path.
- Installing over a table left behind by an older uninstall now adds any columns it's missing, rather than adopting it as-is. Craft records the current schema version at install time, so nothing else would ever have repaired it.
- Uninstalling the plugin now drops its table. Dub keeps the links themselves, so `php craft dub/adopt` rebuilds the local records after a reinstall.

### Fixed
- An entry whose Dub link had been deleted no longer comes back on a different short URL. The link is recreated on the next save, but the create went out without a slug, so Dub minted a random one — quietly moving the entry to a short URL that nobody had shared, and breaking any QR code or printed link pointing at the old one. The recorded slug is reused instead.
- A site whose Base URL can't be resolved no longer breaks saving its entries. Craft falls back to the current request's host in the control panel, but has nothing to fall back to under a console command, a queue job or cron — so the entry's URL came out as a bare path, which Dub rejected, which failed the whole save. Those saves are now skipped with a warning naming the site, and the entry saves normally.
- Permanently deleting an entry now deletes its short links from Dub, rather than leaving them behind. Craft removes the element row before the delete handler runs, and the plugin's foreign key cascaded the local records away with it — so nothing was left to say which links to delete, and they were stranded in the Dub workspace. The site IDs are now captured before the delete. Note that links orphaned by earlier versions aren't cleaned up for you: `dub/check` only inspects links Craft still has a record of, and those records went with the entry — so any left behind by a permanent delete before this release need removing in the Dub dashboard.
- Saving an entry no longer destroys the short link it just created. Craft hard-deletes the provisional draft after every control panel save, and the delete handler acted on that cleanup as though the entry itself had been deleted — removing the link from Dub and the local record moments after the save. Drafts and revisions are now ignored on the delete and restore paths, as they already were on save.
- Moving an entry to the trash no longer deletes its Dub link. The link is archived instead, and un-archived if the entry is restored, so short links and QR codes already in circulation keep working.
- Clearing the **Short Link** slug on one site no longer deletes the other sites' links.
- Saving a multi-site entry no longer fails when its short link key is replayed against the other sites.
- Saving a non-live entry that has no short link no longer makes a redundant Dub API call.

## 1.3.0 - 2026-07-23

### Added
- A **Download** button in the QR code modal, which saves the QR as `qr-<slug>.png`.
- **Full** mode now also displays a download icon in the **QR Code** row.

### Changed
- Sidebar QR codes (both **Icon** and **Full** modes) now open in a modal rather than a new tab.

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