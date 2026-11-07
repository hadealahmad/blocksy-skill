# Header and footer builder

The builder is not a set of theme mods per item. It is one nested theme mod:

- `header_placements`
- `footer_placements`

Every section, row, placement, item, and item value lives inside that one array.

## Header shape

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
        [
          'id' => 'top-row',
          'placements' => [
            ['id' => 'start', 'items' => []],
            ['id' => 'middle', 'items' => []],
            ['id' => 'end', 'items' => []],
            ['id' => 'start-middle', 'items' => []],
            ['id' => 'end-middle', 'items' => []],
          ],
        ],
        // middle-row, bottom-row, offcanvas
      ],
      'mobile' => [
        // same row structure
      ],
    ],
  ],
]
```

- `items` holds the item definitions and their `values`.
- `desktop`/`mobile` rows reference item IDs inside `placements[].items`.
- `settings` holds sticky/transparent/background settings.
- `current_section` chooses the default section.

A header row's placements are `start`, `middle`, `end`, `start-middle`, and
`end-middle`. `offcanvas` has only `start` in the default structure.

## Footer shape

Footer uses columns, not placements:

```php
[
  'current_section' => 'type-1',
  'sections' => [
    [
      'id' => 'type-1',
      'mode' => 'columns',
      'rows' => [
        ['id' => 'top-row', 'columns' => [[], []]],
        ['id' => 'middle-row', 'columns' => [[], [], []]],
        ['id' => 'bottom-row', 'columns' => [['copyright']]],
      ],
      'items' => [
        ['id' => 'copyright', 'values' => ['copyright_text' => '© {current_year} {site_title}']],
      ],
      'settings' => [],
    ],
  ],
]
```

Columns are arrays of item IDs. `items` holds values, like the header.

## Build with Blocksy's own helper

Do not hand-build the row shells. Use:

```php
$header = blocksy_manager()->header_builder->get_structure_for([
  'id' => 'type-1',
  'mode' => 'placements',
  'items' => [
    'desktop' => [
      'middle-row' => ['start' => ['logo'], 'end' => ['menu', 'search']],
    ],
    'mobile' => [
      'middle-row' => ['start' => ['logo'], 'end' => ['trigger']],
      'offcanvas' => ['start' => ['mobile-menu']],
    ],
  ],
]);

$header['items'] = [
  ['id' => 'logo', 'values' => ['logoMaxHeight' => 50]],
  ['id' => 'menu', 'values' => []],
  ['id' => 'search', 'values' => []],
  ['id' => 'trigger', 'values' => []],
  ['id' => 'mobile-menu', 'values' => []],
];

set_theme_mod('header_placements', [
  'current_section' => 'type-1',
  'sections' => [$header],
]);
```

Footer:

```php
$footer = blocksy_manager()->footer_builder->get_structure_for([
  'id' => 'type-1',
  'rows' => [
    'top-row' => ['columns' => [[], []]],
    'middle-row' => ['columns' => [[], []]],
    'bottom-row' => ['columns' => [['copyright']]],
  ],
]);

$footer['items'] = [
  ['id' => 'copyright', 'values' => ['copyright_text' => '© {current_year} {site_title}']],
];
```

The bridge validates and sanitizes both builders before writing.

## Item values

Each item ID has its own options. Use the live `schema.builder.header[]` or
`schema.builder.footer[]` entries, or the files:

- `themes/blocksy/inc/panel-builder/header/<item>/options.php`
- `themes/blocksy/inc/panel-builder/footer/<item>/options.php`
- companion/pro item directories

Only include the values you want to change. Missing values fall back to the
item's defaults.

Examples:

```php
// Logo
['id' => 'logo', 'values' => [
  'logoMaxHeight' => 50,
  'has_site_title' => 'yes',
  'blogname' => 'My Site',
  'has_tagline' => 'no',
]]

// Menu
['id' => 'menu', 'values' => [
  'menu' => 'blocksy_location', // or a nav menu term ID
  'header_menu_type' => 'type-1',
  'headerMenuItemsSpacing' => 25,
]]

// Button
['id' => 'button', 'values' => [
  'header_button_text' => 'Contact',
  'header_button_link' => '/contact/',
  'header_button_size' => 'small',
  'header_button_type' => 'type-1',
]]

// Content Block item (Pro)
['id' => 'content-block', 'values' => [
  'hook_id' => 123, // ct_content_block post ID
]]
```

### Logo special case

Most `ct-image-uploader` options store `{attachment_id: 123}`. The logo and
offcanvas-logo options use `inline_value`, so they store the attachment ID
directly. The safest route is to set the core `custom_logo` theme mod:

```php
set_theme_mod('custom_logo', $attachment_id);
```

The logo view falls back to `custom_logo` when the item has no `custom_logo`
value.

### Widget areas

Footer widget area items have no meaningful values. The sidebar ID is fixed by
the item:

- Header: `ct-header-sidebar-1`
- Footer: `ct-footer-sidebar-1` … `ct-footer-sidebar-6`
- Main sidebar: `sidebar-1`
- Dynamic sidebars: `ct-dynamic-sidebar-<id>`

## Header section settings

`settings` is an array on each header section. From 2.1.60:

| ID | Type | Default | Purpose |
| --- | --- | --- | --- |
| `has_sticky_header` | switch | `no` | Enable sticky |
| `sticky_rows` | image picker | `middle` | `middle`, `top_middle`, `entire_header`, `middle_bottom`, `top`, `bottom` |
| `sticky_effect` | select | `shrink` | Sticky effect |
| `sticky_animation_speed` | number | `0.2` | Animation speed |
| `sticky_offset` | slider | `0` | Offset |
| `sticky_behaviour` | visibility | `{desktop:true,mobile:true}` | Devices |
| `has_transparent_header` | switch | `no` | Enable transparent header |
| `transparent_conditions` | conditions | includes everywhere | When transparent applies |
| `transparent_behaviour` | visibility | `{desktop:true,mobile:true}` | Devices |
| `headerBackground` | background | — | Normal background |
| `transparentHeaderBackground` | background | — | Transparent background |
| `stickyHeaderBackground` | background | — | Sticky background |

## Footer section settings

| ID | Type | Default | Purpose |
| --- | --- | --- | --- |
| `footer_container_structure` | radio | `fixed` | `fixed`, `boxed`, `fluid` |
| `footer_boxed_offset` | responsive slider | `50` | Boxed bottom offset |
| `has_reveal_effect` | visibility | false | Reveal on scroll |
| `footerShadow` | box shadow | — | Footer shadow |
| `footerBackground` | background | — | Footer background |
| `footer_spacing` | spacing | — | Footer spacing |
| `footer_boxed_spacing` | responsive spacing | — | Boxed spacing |
| `footer_container_border_radius` | spacing | — | Radius |

## Free vs Pro items

### Header

**Free theme:** `top-row`, `middle-row`, `bottom-row`, `logo`, `menu`,
`menu-secondary`, `search`, `button`, `text`, `socials`, `cart`,
`mobile-menu`, `trigger`, `offcanvas`, `offcanvas-logo`. The `cart` item
requires WooCommerce.

**Free Companion:** `account`.

**Pro:** `contacts`, `content-block`, `divider`, `language-switcher`,
`menu-tertiary`, `mobile-menu-secondary`, `search-input`, `widget-area-1`.

**Pro, extension-gated:** `color-mode-switcher` (extension `color-mode-switch`),
`compare` and `wish-list` (extension `woocommerce-extra` + WooCommerce, with the
Compare / Wishlist feature switched on in the extension settings).

`language-switcher` is listed by the builder but stays disabled unless WPML,
Polylang, TranslatePress or Weglot is active. `cart` is the same with
WooCommerce.

### Footer

**Free theme:** `top-row`, `middle-row`, `bottom-row`, `copyright`, `menu`,
`socials`, `widget-area-1`…`widget-area-6`.

**Pro:** `menu-secondary`, `logo`, `button`, `text`, `content-block`,
`search-input`, `language-switcher`, `contacts`.

The Pro footer reuses the theme logo/button/text implementations and the
premium header item implementations for content block/search/language/contacts.

### Complete item inventory

`data/builder-items.json` lists every item that exists in Blocksy 2.1.60 +
Companion + Companion Pro (27 header, 20 footer), including the three that the
`data/builder-options.json` snapshot lacks because their extension/plugin was
off. Use it to answer "can I put X in the header/footer?" and to know what has
to be active first.

| Item | Builder | Availability | Needs | Options in snapshot | Options (static parse) |
| --- | --- | --- | --- | --- | --- |
| `bottom-row` | footer | free | — | 22 | 23 |
| `button` | footer | pro | — | 35 | 30 |
| `contacts` | footer | pro | — | 23 | 27 |
| `content-block` | footer | pro | — | 2 | 4 |
| `copyright` | footer | free | — | 7 | 8 |
| `language-switcher` | footer | pro | plugin:wpml, plugin:polylang, plugin:translatepress, plugin:weglot | 14 | 30 |
| `logo` | footer | pro | — | 30 | 31 |
| `menu` | footer | free | — | 10 | 11 |
| `menu-secondary` | footer | pro | — | 10 | 11 |
| `middle-row` | footer | free | — | 22 | 23 |
| `search-input` | footer | pro | — | 36 | 37 |
| `socials` | footer | free | — | 18 | 19 |
| `text` | footer | pro | — | 15 | 18 |
| `top-row` | footer | free | — | 22 | 23 |
| `widget-area-1` | footer | free | — | 6 | 7 |
| `widget-area-2` | footer | free | — | 6 | 7 |
| `widget-area-3` | footer | free | — | 6 | 7 |
| `widget-area-4` | footer | free | — | 6 | 7 |
| `widget-area-5` | footer | free | — | 6 | 7 |
| `widget-area-6` | footer | free | — | 6 | 7 |
| `account` | header | free-companion | — | 57 | 63 |
| `bottom-row` | header | free | — | 25 | 26 |
| `button` | header | free | — | 33 | 30 |
| `cart` | header | free | plugin:woocommerce | 47 | 30 |
| `color-mode-switcher` | header | pro | extension:color-mode-switch | not in snapshot | 18 |
| `compare` | header | pro | extension:woocommerce-extra, plugin:woocommerce | not in snapshot | 21 |
| `contacts` | header | pro | — | 21 | 27 |
| `content-block` | header | pro | — | 2 | 4 |
| `divider` | header | pro | — | 6 | 7 |
| `language-switcher` | header | pro | plugin:wpml, plugin:polylang, plugin:translatepress, plugin:weglot | 27 | 30 |
| `logo` | header | free | — | 28 | 31 |
| `menu` | header | free | — | 36 | 36 |
| `menu-secondary` | header | free | — | 36 | 36 |
| `menu-tertiary` | header | pro | — | 36 | 36 |
| `middle-row` | header | free | — | 25 | 26 |
| `mobile-menu` | header | free | — | 22 | 22 |
| `mobile-menu-secondary` | header | pro | — | 22 | 22 |
| `offcanvas` | header | free | — | 21 | 18 |
| `offcanvas-logo` | header | free | — | 4 | 5 |
| `search` | header | free | — | 37 | 39 |
| `search-input` | header | pro | — | 34 | 37 |
| `socials` | header | free | — | 21 | 22 |
| `text` | header | free | — | 14 | 18 |
| `top-row` | header | free | — | 25 | 26 |
| `trigger` | header | free | — | 21 | 22 |
| `widget-area-1` | header | pro | — | 1 | 1 |
| `wish-list` | header | pro | extension:woocommerce-extra, plugin:woocommerce | not in snapshot | 21 |

- *Options in snapshot* comes from `data/builder-options.json` (live extraction).
  *Static parse* is from `options.php` and matches ~95% of live ids for
  catalogued items; treat it as a hint only.
- For an item that is not in the snapshot, satisfy its conditions on the target
  site, then read its exact option ids and defaults from the live `schema`
  (`builder.header[]` / `builder.footer[]`). Do not guess values from the static
  list.
- `content-block` shows only `content_width` and `margin` until at least one
  Content Block exists; then `hook_id` (the Content Block to render) appears.
- The color mode switcher also adds dark-mode logo options to `logo` and
  `offcanvas-logo` through filters once the extension is active.

**Pro gate:** if the site has the free Companion build and the user asks for a
Pro item, stop and ask for Blocksy Companion Pro installation/activation. Do
not recreate the item with custom HTML/CSS/JS or silently use a different free
item. See `pro-gate.md`.

## Extra sections and conditions (Pro)

Pro can add additional header/footer sections. Use an ID such as
`ct-custom-<hash>` for a normal custom section. `ct-custom-transparent` is a
special reserved ID.

Conditions are stored separately:

- `blocksy_premium_header_conditions`
- `blocksy_premium_footer_conditions`

Shape:

```php
[
  [
    'id' => 'ct-custom-abc123',
    'conditions' => [
      ['type' => 'include', 'rule' => 'singulars', 'payload' => []],
      ['type' => 'exclude', 'rule' => 'page_ids', 'payload' => ['post_id' => 12]],
    ],
  ],
]
```

Each section in `header_placements`/`footer_placements` still needs its own
`id`, `items`, rows/placements, and `settings`.

## Verification

After writing:

```sh
wp blocksy tool run regenerate_dynamic_css
curl -s https://example.com/ | grep -o 'data-header="[^"]*"'
```

The generated CSS must contain the item/row rules and the rendered HTML must
contain the item IDs. If the builder is malformed, the header may disappear;
check the apply warnings/errors and restore the backup.
