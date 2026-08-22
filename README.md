# Dub Links

Create Dub short links for your entries.

## Requirements

- A [Dub](https://refer.dub.co/bensomething) account (affiliate link) and API key. [Learn more.](https://dub.co/docs/api-reference/authentication#api-keys)
- Craft CMS 5.0.0 or later.
- PHP 8.2 or later.

## Installation

To install the plugin, search for "Dub" in the Craft Plugin Store, or install manually using composer.

```
composer require bensomething/craft-dub
```

Then install the plugin via the Craft control panel under **Settings → Plugins**, or from the terminal:

```
php craft plugin/install dub
```

## Configuration

1. Go to **Settings → Plugins → Dub Links** in the Craft control panel.
2. On the **General** tab, enter your Dub API key. You can use an environment variable (e.g. `$DUB_API_KEY`).
3. After saving your API key, the **Domain** field will suggest your available domains. Pick one, or use an environment variable (e.g. `$DUB_DOMAIN`).
4. In the **Sections** field, choose which sections to enable short links for. Leave **All** selected to enable every section that has URLs.
5. On the **Sidebar** tab, choose how the **QR code** appears in the entry sidebar (**None**, **Icon**, or **Full**), set its **style** (size, margin, foreground/background colour), and choose whether to **Show click count**.

### Setting enabled sections via environment

You can override the **Sections** selection with the `DUB_SECTIONS` environment variable, a comma-separated list of section **handles**:

```
DUB_SECTIONS=festivals,crew
```

When set, it takes precedence over the control panel selection (which is shown as read-only in the settings).

## Usage

Once configured, a **Short Link** panel will appear in the sidebar of any entry that belongs to a section with URLs.

- **Creating a short link:** enter a custom slug in the **Short Link** sidebar section and save the entry. If left blank, no short link is created.
- **Updating a short link:** update the short link slug in the sidebar and save. The existing Dub link is updated in place.
- **Deleting a short link:** a short link will be removed from Dub when an entry is deleted or when a short link slug is removed and the entry is saved.
- **Archiving a short link:** a short link will be archived in Dub when an entry is disabled.
- **QR code:** depending on the **Sidebar → QR code** setting, a QR code for the short link is shown in the sidebar as a small icon or a full image.
- **Click count:** enable **Sidebar → Show click count** to display a read-only click total for the short link in the sidebar.

## Adopting existing links

If your Dub workspace already contains short links for your entries — created manually or before installing the plugin — you can hand them over to the plugin in one pass:

```
php craft dub/adopt
```

This scans your workspace, matches each link to a Craft entry by its destination URL, sets the entry's `externalId` on the link so the plugin manages it going forward, and records it locally. Matching uses the host and path together, so sites on separate domains or subdomains are told apart even when they share a path. Links with no matching entry — or a path shared by two sites on the same host — are left untouched.

Add `--dry-run` to preview what would be adopted without making any changes:

```
php craft dub/adopt --dry-run
```

If some of your Dub links point to an old path prefix, use `--rewrite` to remap the destination path when matching. It only applies as a fallback, when the link's original path matches no entry. Pass one or more comma-separated `from=to` prefixes:

```
php craft dub/adopt --rewrite="/areas-stages/=/venues/"
```

## Checking existing links

Saving an entry doesn't re-send a short link that hasn't moved, which keeps a resave cheap. The
trade-off is that the plugin won't notice if a link is deleted or edited in the Dub dashboard —
the entry sidebar keeps showing a short link that no longer resolves.

To find those:

```
php craft dub/check
```

It reports any recorded link that's missing from Dub, or whose short link or destination has
drifted from what Craft recorded, and exits non-zero if anything needs attention — so it can be
run from cron or CI. Add `--fix` to put things back:

```
php craft dub/check --fix
```

It also reports links that have fallen behind Craft — **stale** links. A short link only moves
when its entry is saved, so changing a site's Base URL, or the **Domain** setting, leaves
existing links pointing at the old place until each entry is next saved. Nothing is wrong at
Dub, which is why this is reported separately.

Stale links aren't repaired by `--fix`. The repair is an ordinary resave:

```
php craft resave/entries
```

Since a save no longer re-sends a link that hasn't moved, that sends one request per link that
has actually changed and nothing at all for the rest.

`--fix` reconciles each side according to what it owns. The **destination** belongs to Craft —
it's derived from the entry — so Craft's value is pushed back to Dub. The **slug** belongs to
Dub: renaming a link there is deliberate, and the renamed URL is the one now in circulation, so
the rename is adopted into Craft's record rather than reversed. A link that's gone from Dub
entirely is recreated with the slug, destination and archived state Craft still holds.

A recreated link is a new link at Dub, so its click history doesn't come back — that went with
the link when it was deleted. The short URL and destination are restored, the statistics aren't.

This is the counterpart to `dub/adopt`: adoption brings links that exist at Dub under Craft's
management, while `dub/check` looks the other way, at links Craft thinks it has.

## Templating

Use the `dubLink(entry)` Twig function to output a short link in your templates:

```twig
{% set shortLink = dubLink(entry) %}
{% if shortLink %}
    <a href="{{ shortLink }}">{{ shortLink }}</a>
{% endif %}
```

Use the `dubQr(entry)` Twig function to output the short link's QR code image:

```twig
{% set qr = dubQr(entry) %}
{% if qr %}
    <img src="{{ qr }}" alt="QR code">
{% endif %}
```

![Dub plugin sidebar](resources/craft-dub-1.png)

[![Stable Version](https://img.shields.io/packagist/v/bensomething/craft-dub?label=stable)](https://packagist.org/packages/bensomething/craft-dub)
[![Total Downloads](https://img.shields.io/packagist/dt/bensomething/craft-dub)](https://packagist.org/packages/bensomething/craft-dub)