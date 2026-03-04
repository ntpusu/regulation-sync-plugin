# NTPUSU Regulation Sync

This WordPress plugin fetches regulation HTML from `https://cloud.ntpusu.org` and exposes it locally so editors can refresh the rendered markup with one click.

## Development Environment

This repository is a standalone WordPress plugin, so the practical local setup is:

1. Install a local WordPress environment with PHP, MySQL, and a web server.
   Good options are Local, Laragon, XAMPP, or a Docker-based WordPress stack.
2. Use a PHP version that matches your WordPress site.
   WordPress current recommendations are typically PHP 8.x, but you should match production first.
3. Place this repository under your WordPress plugins directory:
   `wp-content/plugins/ntpusu-regulation-sync`
4. Activate the plugin in the WordPress admin.
5. For development, edit the plugin files directly from this repo and refresh the WordPress site.
6. If you want syntax checks, run:

```bash
php -l wp-content/plugins/ntpusu-regulation-sync/ntpusu-regulation-sync.php
php -l wp-content/plugins/ntpusu-regulation-sync/includes/helpers.php
php -l wp-content/plugins/ntpusu-regulation-sync/includes/sync-service.php
php -l wp-content/plugins/ntpusu-regulation-sync/includes/admin.php
php -l wp-content/plugins/ntpusu-regulation-sync/includes/shortcode.php
```

## How It Works

1. Visit **Regulation Sync** in the WordPress admin.
2. Choose a source:
   Enter a regulation ID, pick one from the fetched regulation list, or paste a custom URL.
3. Optionally select the WordPress page or post that should receive the synced content.
4. Press **Sync Now**.
5. If the source URL matches a regulation page or regulation API URL, the plugin calls the cloud API and stores the `fullText` payload from the single-regulation endpoint.
6. Place `[ntpusu_regulation]` anywhere on the front end.
   By default it uses the current post, or you can override it with `[ntpusu_regulation post_id="123"]`.

## Remote API

The plugin now uses the documented regulation API from:

`https://github.com/NTPU-Student-Headquarters/ntpush-cloud/blob/main/docs/api/regulation.md`

Current endpoints:

- `https://cloud.ntpusu.org/api/regulation/list`
  Returns `[id, titleShort]` pairs.
- `https://cloud.ntpusu.org/api/regulation/single/{id}`
  Returns `titleFull`, `titleShort`, `modifiedType`, `modifiedDate`, `history`, and `fullText`.

## Notes

- The regulation list is loaded from the JSON API instead of scraping the front-end page.
- When the API returns `modifiedDate`, the plugin uses it to update the mapped WordPress post's publish date.
- The plugin sanitizes stored HTML with `wp_kses`, but it still allows the tags needed for remote assets to load.
- If the PHP DOM extension is unavailable, the plugin still stores remote markup, but it cannot trim the response to the `<body>` content or rewrite relative asset URLs.
