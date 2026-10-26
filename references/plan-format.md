# Plan format

A plan is a JSON object consumed by `scripts/apply.php` (WP-CLI) or
`POST /wp-json/blocksy-bridge/v1/apply` (remote). The same plan works through
both adapters.

Only include the sections you need. Every section is optional.

```json
{
  "version": 1,
  "description": "optional human note",
  "extensions": {
    "activate": ["cookies-consent"],
    "deactivate": []
  },
  "theme_mods": {},
  "remove_theme_mods": [],
  "options": {},
  "remove_options": [],
  "menus": [],
  "content_blocks": [],
  "post_meta": {},
  "remove_post_meta": {},
  "cache": true,
  "snapshot": false
}
```

## `version`

Required if present; currently `1`. The bridge rejects other versions.

## `extensions`

```json
{
  "activate": ["custom-fonts", "cookies-consent"],
  "deactivate": ["trending"]
}
```

- IDs are the extension IDs returned by the live `schema` or
  `wp blocksy extension list --format=json`.
- Activation goes through `activate_extension()`, so `onActivation()` runs.
- Pro extensions require a paid plan. The bridge reports an error if the
  extension is unavailable or the plan does not match.
- `woocommerce-extra` requires WooCommerce.

`blocksy_active_extensions` is intentionally not a normal option. To change the
set, use this section.

## `theme_mods`

An object of `option_id => value`. Values are written with `set_theme_mod()` to
the **active** theme.

```json
{
  "maxSiteWidth": 1290,
  "colorPalette": {
    "color1": { "color": "#2563eb" },
    "color2": { "color": "#1d4ed8" },
    "color3": { "color": "#475569" },
    "color4": { "color": "#0f172a" },
    "color5": { "color": "#e2e8f0" },
    "color6": { "color": "#f8fafc" },
    "color7": { "color": "#ffffff" },
    "color8": { "color": "#ffffff" }
  },
  "rootTypography": {
    "family": "System Default",
    "variation": "n4",
    "size": "16px",
    "line-height": "1.65",
    "letter-spacing": "0em",
    "text-transform": "none",
    "text-decoration": "none"
  }
}
```

Rules:

- Use the IDs and value shapes from the live schema.
- `ct-typography` values must be complete.
- Responsive values must include `desktop`, `tablet`, `mobile`.
- `header_placements` and `footer_placements` are validated and sanitized.
- Existing legacy mods are allowed so a backup can be restored.
- New unknown keys are rejected.

## `remove_theme_mods`

```json
["maxSiteWidth", "header_placements"]
```

Removes the mod and reverts to the PHP default. Only allowed/existing keys.

## `options`

An allowlisted object of WordPress options. These are **not** theme mods.

```json
{
  "blocksy_custom_palettes": { "palettes": [] },
  "blocksy_ext_custom_fonts_settings": {
    "fonts": [],
    "stacks": []
  },
  "blocksy_dynamic_sidebars_storage": {}
}
```

Allowed keys:

- `blocksy_custom_palettes`
- `blocksy_dynamic_sidebars_storage`
- `blocksy_ext_custom_fonts_settings`
- `blocksy_ext_local_google_fonts_settings`
- `blocksy_ext_adobe_typekit_settings`
- `blocksy_ext_white_label_settings`
- `blocksy_ext_post_types_extra_settings`
- `blocksy_ext_woocommerce_extra_settings`
- `blocksy_ext_product_reviews_settings`
- `blocksy_ext_mailchimp_credentials`
- `blocksy_ext_demos_current_demo`

Anything outside this list is rejected. `blocksy_active_extensions` is handled
through `extensions`.

## `remove_options`

```json
["blocksy_ext_adobe_typekit_settings"]
```

Deletes the option if present. Same allowlist.

## `menus`

```json
[
  {
    "name": "Main Menu",
    "location": "menu_1",
    "replace": true,
    "items": [
      { "key": "home", "title": "Home", "type": "custom", "url": "/" },
      { "key": "blog", "title": "Blog", "type": "custom", "url": "/blog/" },
      {
        "key": "services",
        "title": "Services",
        "type": "post_type",
        "page_id": 12
      }
    ]
  }
]
```

Fields:

| Field | Meaning |
| --- | --- |
| `name` | Menu name. Creates it if missing. |
| `location` or `locations` | One location string or an array. Must be a registered location. |
| `replace` | Default `true`: delete existing items and rebuild. `false`: append. |
| `delete` | `true`: delete the menu instead of building it. |
| `items` | Flat array; use `key` and `parent` to build a tree. |

Item fields:

| Field | Meaning |
| --- | --- |
| `key` | Stable key used for parent references and idempotency. |
| `title` | Menu label. |
| `type` | `custom`, `post_type`, or `taxonomy`. |
| `url` | For `custom`. |
| `page_id` / `object_id` | For `post_type`. |
| `slug` | Alternative for `post_type`; resolved with `get_page_by_path()`. |
| `taxonomy`, `term_id` | For `taxonomy`. |
| `parent` | Key of the parent item. |
| `classes` | String or array. |
| `target` | e.g. `_blank`. |
| `description`, `attr_title` | Standard menu item fields. |
| `meta` | Written to the nav menu item's `blocksy_post_meta_options`. Used by Advanced Menu. |

## `content_blocks`

```json
[
  {
    "key": "single-notice",
    "title": "Single Notice",
    "template_type": "hook",
    "status": "publish",
    "content": "<!-- wp:paragraph --><p>Notice</p><!-- /wp:paragraph -->",
    "meta": {
      "conditions": [
        { "type": "include", "rule": "single_post", "payload": [] }
      ],
      "location": "blocksy:content:top",
      "priority": 10
    }
  }
]
```

Fields:

| Field | Meaning |
| --- | --- |
| `key` | Required unless `id` is supplied. Stored as `_blocksy_bridge_key` for idempotency. |
| `id` | Optional explicit `ct_content_block` post ID. Useful for same-site restore. Omit for cloning. |
| `title` | Post title. |
| `template_type` | `header`, `footer`, `single`, `archive`, `404`, `hook`, `popup`, `maintenance`, `nothing_found`. |
| `status` | `publish`, `draft`, `private`. Default `publish`. |
| `content` | Gutenberg block markup. |
| `meta` | Merged into `blocksy_post_meta_options`. |
| `delete` | `true`: delete the block by `key`/`id`. |

`meta.conditions` is required for display. Shape:

```json
[
  { "type": "include", "rule": "everywhere", "payload": [] },
  { "type": "exclude", "rule": "page_ids", "payload": { "post_id": 12 } }
]
```

`type` is `include` or `exclude`; `rule` is a registered condition ID; `payload`
is rule-specific. Nested groups use `{ "relation": "OR", "conditions": [...] }`.

## `post_meta`

```json
{
  "sample-page": {
    "page_structure_type": "type-2",
    "disable_header": "no"
  },
  "42": {
    "disable_footer": "yes"
  }
}
```

Keys are post IDs or slugs. Values are merged into
`blocksy_post_meta_options`. `styles_descriptor` is removed so Blocksy
regenerates the page CSS.

Allowed keys come from the post/page meta schema plus `disable_header` and
`disable_footer`.

## `remove_post_meta`

```json
{
  "sample-page": ["page_structure_type"]
}
```

Removes the listed keys. If the meta becomes empty, the meta row is deleted.

## `cache`

Default `true`. After a successful non-dry apply:

1. `blocksy_manager()->db->wipe_cache()`
2. `do_action('blocksy:dynamic-css:refresh-caches')`
3. `do_action('blocksy:cache-manager:purge-all')`
4. `wp_cache_flush()`

Set `false` only when batching and you will call `/cache` or
`wp blocksy tool run regenerate_dynamic_css` yourself.

## `snapshot`

Default `false`. When `true`, the apply response includes a `snapshot` object
with the current export (theme mods, options, menus, content blocks). This can
be large. Prefer a separate `export` before apply.

## Response

```json
{
  "dry_run": false,
  "ok": true,
  "actions": [
    { "type": "theme_mod", "op": "set", "key": "maxSiteWidth", "before": null, "after": 1290 }
  ],
  "warnings": [],
  "errors": [],
  "cache": {
    "blocksy_cache_wiped": true,
    "dynamic_css": true,
    "page_caches": true
  }
}
```

- `ok` is false if `errors` is non-empty.
- `warnings` do not block the apply.
- `actions` is the audit trail; review it after a dry run.
- `errors` usually mean a typo, a version mismatch, a missing extension, or a
  plan value outside the schema.

## Portability between sites

The same plan can seed a new site, but IDs are local:

- **Menus** resolve by name and location. They are portable.
- **Content Blocks** resolve by `key`. Portable export removes the old post
  `id`, so a target site creates a new block and stores the key.
- **Post meta** uses post IDs or slugs. Prefer slugs; IDs differ between sites.
- **Attachment IDs** are local. `custom_logo`, hero backgrounds, custom font
  `attachment_id` values, and media fields do not move with the plan. Either
  upload the media on the target and remap the IDs, or use URL-based font
  variations and skip media fields.
- **Theme mods** such as `custom_logo`, `blog_custom_hero_background`, and
  `single_blog_post_custom_hero_background` may contain attachment IDs. Check
  them after a cross-site apply.
- **Menu item IDs** in an export are removed by `--portable`; the `parent` field
  is converted to a stable item key.

Use `export --portable` for cloning. Use a non-portable export for a same-site
backup, where IDs are still valid.
