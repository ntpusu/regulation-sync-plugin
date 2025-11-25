# NTPUSU Regulation Sync

This WordPress plugin fetches HTML from the NTPUSU regulation system and exposes it locally so editors can refresh the rendered markup with one click.

## How it works

1. Copy the `ntpusu-regulation-sync` directory into your WordPress site's `wp-content/plugins` folder and activate the plugin.
2. Visit **Regulation Sync** in the WordPress admin menu.
3. Choose a source: enter a regulation ID (builds `/regulation/{id}/embed`), pick from the fetched regulation list (links scraped from `https://regsys.ntpusu.org/regulation/`), or paste a custom page URL.
4. (Optional) Select the WordPress page you want to map to this regulation, or enter any post ID manually (you must be able to edit that post). The plugin will remember that relationship and store the synced HTML on that post so the shortcode can render the right content automatically.
5. Press **Sync Now**. When the URL looks like `https://regsys.ntpusu.org/regulation/{id}` (with or without `/embed`), the plugin calls the site's JSON endpoint at `https://regsys.ntpusu.org/api/regulation/{id}` and stores the `fullText` payload so you get the actual rendered law instead of the empty SPA shell. For other URLs it falls back to WordPress' [HTTP API (`wp_remote_get`)](/websites/wordpress#topic=wp_remote_get), normalizes asset URLs, stores the markup, and shows a preview.
6. Place the `[ntpusu_regulation]` shortcode anywhere on the front end. By default it uses the page it appears on, but you can override it with `[ntpusu_regulation post_id="123"]` to render a different mapped post.

## Notes

- The plugin sanitizes the stored content with `wp_kses`, but it whitelists `script`, `link`, and related tags so Nuxt assets from regsys.ntpusu.org continue to work.
- Pasting a direct regulation URL (for example `https://regsys.ntpusu.org/regulation/7` or the `/embed` variant) is the easiest way to guarantee that the plugin switches to the `/api/regulation/{id}` endpoint and waits for the finished HTML payload before saving it.
- Each mapped post keeps its own copy of the synced HTML in post meta. You can see the mapping table on the Regulation Sync admin screen and remove a mapping without touching the rest of the content.
- Users need the `edit_post` capability on a given post to create or remove a mapping for that post; global sync without a target page still requires higher privileges.
- You can sync an individual mapping from the table, sync all mappings you can edit, or enable a twice-daily scheduled sync (admins only for scheduling).
- If your hosting environment lacks the PHP DOM extension, the plugin will still store the remote markup, but it will not trim the response down to the `<body>` section or rewrite relative URLs.
- PHP is not installed in this workspace, so the file was not linted locally. Run `php -l wp-content/plugins/ntpusu-regulation-sync/ntpusu-regulation-sync.php` inside your WordPress project if you want to double-check syntax.
