# Dub

Create [Dub](https://dub.co) short links for your entries.

## Requirements

- A [Dub](https://dub.co) API key.
- Craft CMS 5.0.0 or later.
- PHP 8.2 or later.

## Installation

```
composer require bensomething/craft-dub
```

Then install the plugin via the Craft control panel under **Settings → Plugins**, or from the terminal:

```
php craft plugin/install dub
```

## Configuration

1. Go to **Settings → Plugins → Dub** in the Craft control panel.
2. Enter your Dub API key. You can use an environment variable (e.g. `$DUB_API_KEY`).
3. After saving your API key, the **Domain** section will display a dropdown of your available domains.

## Usage

Once configured, a **Short Link** panel will appear in the sidebar of any entry that belongs to a section with URLs.

- **Creating a short link:** enter a custom slug in the sidebar field and save the entry. If left blank, no short link is created.
- **Updating a short link** — change the key in the sidebar and save. The existing Dub link is updated in place.
- **Deleting a short link** — the short link is automatically removed from Dub when an entry is deleted.

Short links are only created for entries in the primary site that belong to sections with URLs.
