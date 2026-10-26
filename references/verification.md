# Verification, cache, and rollback

No plan is done until the front end and the generated CSS prove it.

## 1. Read the apply report

```json
{
  "ok": true,
  "actions": [...],
  "warnings": [...],
  "errors": [...],
  "cache": {...}
}
```

- `ok: false` → do not continue; fix the `errors`.
- `warnings` → read them. Common warnings: conditions may not display,
  `unfiltered_html` missing, a menu location is not registered, an unknown
  condition rule, or an extension feature that needs WooCommerce.
- `actions` → the diff. Check that it contains the changes you expected.
- `cache.dynamic_css: true` → the CSS refresh ran.

## 2. Check the generated CSS

```sh
wp blocksy tool run regenerate_dynamic_css
```

Then inspect `wp-content/uploads/blocksy/css/global.css`:

```sh
grep -o 'palette-color-1:[^;]*' uploads/blocksy/css/global.css
grep -o 'normal-container-max-width:[^;]*' uploads/blocksy/css/global.css
grep -o 'theme-font-size:42px' uploads/blocksy/css/global.css
grep -o 'logo-max-height:[^;}]*' uploads/blocksy/css/global.css
```

The file should exist when `dynamic_css_file` is `file`. If the setting is
`inline`, the CSS is in the page head instead.

Override the CSS path:

```php
$uploads = wp_upload_dir();
echo $uploads['basedir'] . '/blocksy/css/global.css';
```

## 3. Check the front end

```sh
curl -sS https://example.com/ -o /tmp/home.html
curl -sS https://example.com/sample-page/ -o /tmp/page.html
curl -sS https://example.com/hello-world/ -o /tmp/single.html
```

Check:

- HTTP status is 200 (or 503 for maintenance mode for logged-out users).
- The expected header/footer markup is present:
  ```sh
  grep -o 'data-header="[^"]*"' /tmp/home.html
  grep -o 'data-footer="[^"]*"' /tmp/home.html
  ```
- Builder item IDs render:
  ```sh
  grep -o 'data-id="logo"\|data-id="menu"\|data-id="search"' /tmp/home.html
  ```
- A Content Block marker renders on the intended views:
  ```sh
  grep -c 'YOUR-UNIQUE-MARKER' /tmp/single.html
  ```
- A per-page sidebar/structure change appears only on the intended page:
  ```sh
  grep -c 'ct-sidebar' /tmp/page.html
  ```
- The menu appears:
  ```sh
  grep -o 'menu-item' /tmp/home.html | head
  ```

Use unique markers in test content so verification is unambiguous. The example
plan uses `example notice rendered by a Blocksy Content Block`.

## 4. Check the database-level state

WP-CLI:

```sh
# Active theme mods (real JSON)
wp eval 'echo json_encode(get_theme_mods(), JSON_PRETTY_PRINT);'

# Active extensions
wp option get blocksy_active_extensions --format=json

# Content blocks
wp post list --post_type=ct_content_block --fields=ID,post_title,post_status

# Menu assignment
wp menu location list

# Theme mods option name
wp option get theme_mods_blocksy-child --format=json   # if child active
```

Do not rely on `wp theme mod get --all --format=json` for nested arrays; WP-CLI
flattens them badly. Use `wp eval 'echo json_encode(get_theme_mods());'`.

## 5. Check per-page CSS

Per-page settings live in `blocksy_post_meta_options`. Blocksy stores a
`styles_descriptor` there after the first render. When you write new page meta,
the bridge removes `styles_descriptor`, so the next render regenerates it. If
you write page meta manually, remove it yourself:

```php
$meta = get_post_meta($post_id, 'blocksy_post_meta_options', true) ?: [];
unset($meta['styles_descriptor']);
update_post_meta($post_id, 'blocksy_post_meta_options', $meta);
```

## 6. Cache checklist

| What | Command |
| --- | --- |
| Blocksy dynamic CSS | `wp blocksy tool run regenerate_dynamic_css` |
| Same from PHP | `do_action('blocksy:dynamic-css:refresh-caches')` |
| Page/object caches | `do_action('blocksy:cache-manager:purge-all')` |
| WordPress object cache | `wp cache flush` |
| Blocksy in-memory mods | `blocksy_manager()->db->wipe_cache()` (same process) |
| Per-post descriptor | remove `styles_descriptor` from the meta |
| Mega menu transient | `blocksy-mega-menu-ext-timestamp` (activation/deactivation clears it) |

Remote: `node scripts/remote.mjs cache`.

## 7. Rollback

There is no database transaction. The safe pattern is:

1. **Export before apply** and keep the backup.
2. If the result is wrong, apply a **cleanup plan**:
   - `remove_theme_mods` for keys added by the failed plan.
   - `menus: [{name, delete: true}]` for menus created by the plan.
   - `content_blocks: [{key, delete: true}]` for blocks created by the plan.
   - `remove_post_meta` for per-page keys added by the plan.
   - `cache: true`.
3. Re-apply the backup's `theme_mods`, `options`, `menus`, and
   `content_blocks` for anything that should be restored.

Example cleanup:

```json
{
  "version": 1,
  "remove_theme_mods": ["header_placements", "footer_placements", "colorPalette"],
  "menus": [{ "name": "Example Main Menu", "delete": true }],
  "content_blocks": [{ "key": "example-single-notice", "delete": true }],
  "remove_post_meta": { "sample-page": ["page_structure_type"] },
  "cache": true
}
```

If a menu was deleted and `nav_menu_locations` still points at it, WordPress
may normalize the location to `0`. Set `nav_menu_locations` back to the desired
map, or remove the mod to revert to the default.

## 8. Common errors

| Error | Meaning | Fix |
| --- | --- | --- |
| `Theme mod 'x' is not in the Blocksy allowlist` | Typo, wrong version, or inactive extension | Re-run `schema`; activate the extension first |
| `Option 'x' is not in the Blocksy Bridge allowlist` | Not a Blocksy option | Use `theme_mods` or the correct `blocksy_*` option |
| `Extension 'x' is not available` | Pro extension on free plugin, or wrong plan | Check `schema.extensions[].tier/plans` |
| `Content block 'x' has unknown meta key` | Key not in that template type's schema | Read `content_block_options[type]` |
| `Content block 'x' has no conditions` | It will never display | Add an include condition |
| `Content block 'x' uses condition rule 'y' which is not registered` | Extension/plugin that registers the rule is inactive, or typo | Activate the provider or use a known rule |
| `Menu location 'x' is not registered` | Theme/Companion version difference | Use `schema.menu_locations` |
| `The current user lacks unfiltered_html` | Multisite admin restriction | Use a super admin or simplify the block content |
| CSS does not change | Cached mods or stale CSS file | Wipe Blocksy cache, regenerate, check `dynamic_css_file` |
| Header disappeared | Malformed builder array | Restore backup; validate `sections`/`rows` |

## 9. Visual check

For a design change, also look at the page:

- desktop, tablet, and mobile widths;
- header sticky/transparent states;
- mobile offcanvas;
- footer reveal effect;
- popup trigger;
- cookie banner;
- RTL/Arabic if the site is RTL.

A browser screenshot is the fastest way to catch a builder that is valid PHP
but visually wrong.
