# Where Blocksy stores settings

All paths below were verified on WordPress 7.1.3 with Blocksy 2.1.60 and
Blocksy Companion Pro 2.1.60, active theme `blocksy-child`.

## 1. Theme + child theme: WordPress theme mods

Blocksy's customizer is a normal WordPress customizer. Every customizer option
is registered as a `theme_mod` and stored in the option named after the
**active stylesheet**:

| Active theme | Option |
| --- | --- |
| Blocksy parent | `theme_mods_blocksy` |
| Blocksy Child | `theme_mods_blocksy-child` |

`blocksy_get_theme_mod()` reads `get_theme_mods()`, so with the child active
only `theme_mods_blocksy-child` is used. The parent's `theme_mods_blocksy` is
ignored. This is the single most important gotcha.

The option value is a PHP array. Keys are the plain Blocksy option ids, for
example:

```php
[
    'maxSiteWidth' => 1290,
    'colorPalette' => ['color1' => ['color' => '#2872fa'], ...],
    'header_placements' => [...],
    'footer_placements' => [...],
    'dynamic_css_file' => 'file',
]
```

Defaults are not all stored. Removing a mod reverts to the value in the PHP
option definition.

### Special theme mods

| Key | Meaning |
| --- | --- |
| `header_placements` | Header builder data: rows, placements, item values, section settings. |
| `footer_placements` | Footer builder data: rows, columns, item values, section settings. |
| `blocksy_premium_header_conditions` | Display conditions for extra header sections. |
| `blocksy_premium_footer_conditions` | Display conditions for extra footer sections. |
| `dynamic_css_file` | `file` (write `uploads/blocksy/css/global.css`) or `inline`. |
| `nav_menu_locations` | Core nav menu locations. |
| `sidebars_widgets` | Widget assignment snapshot (also a core option). |
| `custom_logo` | Core logo attachment id; used as the logo item fallback. |
| `custom_css_post_id` | Core Additional CSS post id. |

## 2. Header/footer builder

`header_placements` is one theme mod containing every section and every item
value. There are no separate theme mods per header item.

Header shape:

```php
[
    'current_section' => 'type-1',
    'sections' => [
        [
            'id' => 'type-1',
            'mode' => 'placements',
            'items' => [
                ['id' => 'logo', 'values' => ['logoMaxHeight' => 60]],
                ['id' => 'menu', 'values' => []],
                ['id' => 'trigger', 'values' => []],
                ['id' => 'mobile-menu', 'values' => []],
            ],
            'settings' => [],
            'desktop' => [
                ['id' => 'top-row', 'placements' => [
                    ['id' => 'start', 'items' => []],
                    ['id' => 'middle', 'items' => []],
                    ['id' => 'end', 'items' => []],
                    ['id' => 'start-middle', 'items' => []],
                    ['id' => 'end-middle', 'items' => []],
                ]],
                // middle-row, bottom-row, offcanvas
            ],
            'mobile' => [ /* same row structure */ ],
        ],
    ],
]
```

Footer shape uses `mode: 'columns'` and `rows[].columns`:

```php
[
    'id' => 'type-1',
    'mode' => 'columns',
    'rows' => [
        ['id' => 'top-row', 'columns' => [[], []]],
        ['id' => 'middle-row', 'columns' => [[], [], []]],
        ['id' => 'bottom-row', 'columns' => [['copyright']]],
    ],
    'items' => [
        ['id' => 'copyright', 'values' => ['copyright_text' => '...']],
    ],
    'settings' => [],
]
```

Item ids are the directory names in
`inc/panel-builder/header/*` (plus companion items in
`framework/features/header/items` and
`framework/premium/features/premium-header/items`). Each item's values use the
same option ids and value shapes as the option files in that directory.

The builder can be constructed with Blocksy's own helper:

```php
$structure = blocksy_manager()->header_builder->get_structure_for([
    'id' => 'type-1',
    'mode' => 'placements',
    'items' => [
        'desktop' => ['middle-row' => ['start' => ['logo'], 'end' => ['menu']]],
        'mobile' => [
            'middle-row' => ['start' => ['logo'], 'end' => ['trigger']],
            'offcanvas' => ['start' => ['mobile-menu']],
        ],
    ],
]);
$structure['items'] = [
    ['id' => 'logo', 'values' => ['logoMaxHeight' => 60]],
    // ...
];
set_theme_mod('header_placements', [
    'current_section' => 'type-1',
    'sections' => [$structure],
]);
```

Verified: this changes the rendered header and the generated CSS.

## 3. Companion + Companion Pro

### Plugin activation

Only one of the two plugins is active at a time. The Pro build includes the
free framework; activating Pro deactivates the free plugin. In this site:

```text
active_plugins = ['blocksy-companion-pro/blocksy-companion.php']
```

### Extensions

Active extensions are an option, not a theme mod:

```text
blocksy_active_extensions = ['custom-fonts', 'trending', ...]
```

Ids are lower-case and sometimes differ from the folder name (`trending` is the
"Trending Posts" extension). Always activate through the API/CLI so the
extension's `onActivation()` runs:

```sh
wp blocksy extension list --format=json
wp blocksy extension activate custom-fonts
wp blocksy extension deactivate custom-fonts
```

Do not write `blocksy_active_extensions` directly unless you know the extension
has no activation work.

### Pro licence

Premium extensions and features check the Freemius plan. This site has an
active Agency licence, so `blocksy_companion_can_use_premium_code()` is true and
`blocksy_companion_get_capabilities()->get_plan()` returns `agency`.
License data lives in Freemius options (`fs_accounts`, `fs_api_cache`); do not
edit it by hand. Use `wp blocksy license activate <key>` if needed.

### Extension settings

Most extensions keep their settings in one of two places:

| Kind | Storage | Examples |
| --- | --- | --- |
| Customizer options added by an extension | theme mods (active theme) | `cookie_consent_*`, `trending_block_*`, `newsletter_subscribe_*`, `header_scripts` |
| Dedicated extension option | `wp_options` | `blocksy_ext_custom_fonts_settings`, `blocksy_ext_local_google_fonts_settings`, `blocksy_ext_adobe_typekit_settings`, `blocksy_ext_white_label_settings`, `blocksy_ext_post_types_extra_settings`, `blocksy_ext_woocommerce_extra_settings`, `blocksy_ext_product_reviews_settings` |

Other well-known options:

| Option | Used by |
| --- | --- |
| `blocksy_custom_palettes` | Custom color palettes (`['palettes' => [...] ]`). |
| `blocksy_dynamic_sidebars_storage` | Multiple Sidebars extension. |
| `blocksy_ext_mailchimp_credentials` | Newsletter Subscribe. |
| `blocksy_ext_demos_current_demo` | Demo installer. |
| `blocksy_taxonomy_lookup_regeneration_state` | WooCommerce filters lookup table. |

Custom Fonts shape:

```php
[
    'fonts' => [
        [
            'name' => 'My Font',
            'variations' => [
                ['variation' => 'n4', 'attachment_id' => 123],
                ['variation' => 'n7', 'url' => 'https://example.com/font-700.woff2'],
            ],
            'preloads' => ['variations' => ['n4']],
        ],
    ],
    'stacks' => ['neo-grotesque'],
]
```

The typography family key for a custom font is
`ct_font_<snake_case_name>` (e.g. `ct_font_my_font`).

Local Google Fonts needs a three-step download/cache process
(`start_fonts_persistence`, `persist_fonts_for`, `conclude_fonts_persistence`),
not just an option write.

Adobe Fonts stores `project_id` and the font list.

## 4. Content Blocks, popups, templates (Pro)

Content Blocks are a custom post type:

- Post type: `ct_content_block`
- Template type: post meta `template_type` with one of
  `header`, `footer`, `single`, `archive`, `404`, `hook`, `popup`,
  `maintenance`, `nothing_found`.
- Conditions and template options: post meta `blocksy_post_meta_options`
  (PHP array, same meta key as per-page settings).
- Post content: normal Gutenberg block markup.

Condition shape:

```php
[
    'conditions' => [
        ['type' => 'include', 'rule' => 'everywhere', 'payload' => []],
        ['type' => 'exclude', 'rule' => 'page_ids', 'payload' => ['post_id' => 12]],
    ],
]
```

Rules include `everywhere`, `singulars`, `archives`, `front_page`, `blog`,
`search`, `404`, `post_ids`, `page_ids`, `custom_post_type_ids`,
`post_with_taxonomy_ids`, `taxonomy_ids`, `user_role`, `start_end_date`,
`schedule_date`, `request_url`, `request_cookie`, `request_referer`, and
others from `framework/features/conditions/rules/*`.

Selection is "newest matching content block wins" for a given template type
(`get_posts()` default order). Avoid leaving overlapping blocks.

Verified: creating a `ct_content_block` with `template_type=header` and an
`everywhere` condition replaces the builder header on the front end.

## 5. Per-page/post settings

Stored in post meta `blocksy_post_meta_options` as a PHP array. The keys are the
same ids as the metabox options (`page_structure_type`,
`content_style_source`, `disable_header`, `disable_footer`, etc.). For the blog
page/shop page only `disable_header` and `disable_footer` are kept.

The theme also registers a REST field `blocksy_meta` for post types, so
`POST /wp-json/wp/v2/pages/<id>` can update it with an application password.

The generated styles descriptor (`styles_descriptor`) lives inside this meta.
Remove it before writing new values so Blocksy regenerates the per-post CSS.

## 6. Menus and widgets

- Menus are core WordPress: `wp menu create`, `wp menu item add-*`,
  `wp menu location assign`.
- Header/footer menu items store either `blocksy_location` (use the theme
  location) or a nav menu term id in `values.menu`.
- Widget areas: `sidebar-1`, `ct-header-sidebar-1`,
  `ct-footer-sidebar-1` … `ct-footer-sidebar-6`.
- Dynamic sidebars use `ct-dynamic-sidebar-<id>`.
- Block widgets live in `widget_block` + `sidebars_widgets`; classic widgets in
  `widget_<type>`.

## 7. Caches

| Thing | Location / action |
| --- | --- |
| Global dynamic CSS | `wp-content/uploads/blocksy/css/global.css` |
| Global CSS descriptor transient | `blocksy_dynamic_styles_descriptor` |
| Per-post CSS descriptor | `styles_descriptor` inside `blocksy_post_meta_options` |
| Regenerate | `wp blocksy tool run regenerate_dynamic_css` |
| Same from PHP | `do_action('blocksy:dynamic-css:refresh-caches')` |
| Purge page/object caches | `do_action('blocksy:cache-manager:purge-all')` |
| Mega menu transient | `blocksy-mega-menu-ext-timestamp` |

Blocksy's `Database` class caches all theme mods in memory. If you write mods and
regenerate CSS in the same PHP process, call
`blocksy_manager()->db->wipe_cache()` before the refresh. Across separate
WP-CLI invocations this is not needed.

## 8. Running WP-CLI on this LocalWP site

```sh
SITE=/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public
SOCKET=/home/hadi/.config/Local/run/NJVUbgRm4/mysql/mysqld.sock

cd "$SITE"
php -d mysqli.default_socket="$SOCKET" /usr/local/bin/wp <command> --allow-root
```

Without the socket override WP-CLI fails with
`Error establishing a database connection`.

To discover the run dir automatically, find the run config whose
`conf/nginx/site.conf` contains the site's `app/public` path, then read
`conf/mysql/my.cnf` or `conf/php/php.ini` for the socket.

Web URL for this site: `http://localhost:10119`.
