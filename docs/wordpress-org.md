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

The plugin includes complete `ru_RU` and `en_US` catalogs: PO sources, compiled MO files and block-editor JSON files. English remains the source language. No separate download is required for either interface. WordPress selects the administrator's profile language; "Site Default" follows Settings → General → Site Language. WordPress.org language packs retain their normal lookup priority. Approval on WordPress.org is still required for the directory description and centrally distributed language packs.

After changing source strings, run `wp i18n make-pot . languages/threadtandem-sync-for-telegram.pot --exclude=dist,tests,tools,docs,wordpress-org-assets,languages --slug=threadtandem-sync-for-telegram`. Update the Russian PO, then run `php tools/sync-translations.php /path/to/wordpress` to align both catalogs and remove stale strings. Regenerate the tracked runtime files with `wp i18n make-mo languages` and `wp i18n make-json languages --no-purge`. The build allowlist includes both language catalogs. Do not increment the version or prepare a release until the owner explicitly requests it.

The default article-link label in Telegram follows the site's language, independently of the administrator's interface language. Leave the field empty for that default; a custom label stays exactly as saved.

Official asset requirements: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
