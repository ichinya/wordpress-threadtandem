# WordPress.org directory assets

Plugin slug: `threadtandem-sync-for-telegram`.

Copy only the PNG files from `wordpress-org-assets/` to the top-level SVN `assets/` directory, alongside `trunk/` and `tags/`. These are directory images, not plugin runtime assets. Keep them outside the installable ZIP.

| File | View |
| --- | --- |
| `icon-128x128.png` | Standard plugin directory icon |
| `icon-256x256.png` | High-DPI plugin directory icon |
| `screenshot-1.png` | Connection, synchronization and moderation settings |
| `screenshot-2.png` | Per-post Telegram publishing panel in the block editor |
| `screenshot-3.png` | Native comment thread with the Telegram author link and nested website reply |
| `screenshot-1-ru.png` | Russian settings interface |
| `screenshot-2-ru.png` | Russian editor interface |
| `screenshot-3-ru.png` | Russian comment thread |

The numbered `Screenshots` entries in `readme.txt` supply the captions. The `-ru` files override the English images in the Russian directory. Set `svn:mime-type` to `image/png` on each PNG.

## Icon

The icon represents two linked conversations on a cobalt background. Generated with the built-in imagegen tool on 2026-10-29. The master and exact generation prompt are retained in `docs/branding/`. The 128px and 256px PNGs are resized exports of the master; both use an opaque background. No official WordPress or Telegram logo is included. Directory icons are distributed separately from the plugin ZIP.

## Screenshot provenance

Captured on 2026-10-28 from the actual ThreadTandem 1.1.0 interface in a disposable WordPress 7.1.3 / PHP 8.4 installation. The editor uses Twenty Twenty-Five; native comments use Twenty Twenty-One. Settings and conversation data are demonstration fixtures; connection status is seeded rather than a new Telegram API verification. External HTTP, email and WP-Cron were disabled. The fixtures use example.org and invented bot/channel/group identifiers. Screenshots contain no real token or private conversation. The images are cropped browser captures, with no replacement controls or interface text.

The Russian interface uses a locally compiled development language pack. It does not establish that the Russian translation has already been approved on WordPress.org.

## Translations

Project: https://translate.wordpress.org/projects/wp-plugins/threadtandem-sync-for-telegram/

The Russian source file is `languages/threadtandem-sync-for-telegram-ru_RU.po`. Review it as a fluent speaker before submitting it to the Russian translation project. Translations must be approved by a locale translation editor before WordPress.org distributes a language pack. Do not bundle the development PO/MO files with the directory release.

Official asset requirements: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
