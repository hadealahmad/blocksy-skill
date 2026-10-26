# Extensions and free/pro boundaries

There are 15 extensions in the Blocksy Companion build. Four are free; eleven
require a paid Companion licence. Plan requirements vary.

**Pro gate:** if the site has the free Companion build (or no valid licence) and
the user asks for a Pro extension, do not fake or bypass it. Stop and ask the
user to install and activate Blocksy Companion Pro first. See
`pro-gate.md`.

Check the live state:

```sh
wp blocksy extension list --format=json
```

or, over REST, `node scripts/remote.mjs schema` and read `.extensions`.

Activate/deactivate through the API/CLI so `onActivation()` runs:

```sh
wp blocksy extension activate custom-fonts
wp blocksy extension deactivate custom-fonts
```

```json
{ "extensions": { "activate": ["custom-fonts"], "deactivate": [] } }
```

Do not write `blocksy_active_extensions` by hand unless you are restoring a
backup and know the extension has no activation work. The bridge's
`extensions` section is the safe path.

## Extension matrix

| ID | Name | Tier | Plan | Requires | Storage | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `cookies-consent` | Cookies Consent | **Free** | — | — | theme mods `cookie_consent_*`, `forms_cookie_consent_*` | Banner type, period, content, accept/decline labels, colors |
| `newsletter-subscribe` | Newsletter Subscribe | **Free** | — | — | theme mods `newsletter_subscribe_*`; option `blocksy_ext_mailchimp_credentials` | Mailchimp list ID, title/text, labels, styles |
| `product-reviews` | Product Reviews | **Free** | — | — | option `blocksy_ext_product_reviews_settings`; CPT `blc-product-review`; post meta `blocksy_product_review_options` | Review schema/fields per review post |
| `trending` | Trending Posts | **Free** | — | — | theme mods `trending_block_*`, `trendingBlock*` | Label, location, conditions, styles |
| `code-snippets` | Custom Code Snippets | **Pro** | Any paid | — | theme mods `header_scripts`, `header_after_body_scripts`, `footer_scripts`; same keys in per-post meta | Global and per-page scripts |
| `color-mode-switch` | Color Mode Switch | **Pro** | Any paid | — | theme mod `darkColorPalette`; header item `color-mode-switcher` values | Dark palette + switch item + `default_color_mode` |
| `custom-fonts` | Custom Fonts | **Pro** | Any paid | — | option `blocksy_ext_custom_fonts_settings` | Uploaded/variable fonts + font stacks |
| `local-google-fonts` | Local Google Fonts | **Pro** | Any paid | — | option `blocksy_ext_local_google_fonts_settings` | Downloads CSS/font files; 3-step persistence |
| `adobe-typekit` | Adobe Fonts | **Pro** | Any paid | — | option `blocksy_ext_adobe_typekit_settings` | Kit/project ID + font list |
| `mega-menu` | Advanced Menu | **Pro** | Any paid | — | nav menu item meta `blocksy_post_meta_options` | Mega columns, layouts, icons, badges, content blocks |
| `post-types-extra` | Post Types Extra | **Pro** | Personal, Professional, Agency (v2) | — | option `blocksy_ext_post_types_extra_settings` | Read time, dynamic data, filtering, taxonomy customization |
| `shortcuts` | Shortcuts Bar | **Pro** | Any paid | — | theme mods `shortcuts_*` | Mobile bottom bar: items, visibility, conditions, styles |
| `sidebars` | Multiple Sidebars | **Pro** | Any paid | — | option `blocksy_dynamic_sidebars_storage` | Extra widget areas + conditions |
| `white-label` | White Label | **Pro** | Agency | — | option `blocksy_ext_white_label_settings` | Branding, hide billing/account, plugin/theme labels |
| `woocommerce-extra` | Shop Extra | **Pro** | Personal, Professional, Agency (v2) | WooCommerce | option `blocksy_ext_woocommerce_extra_settings` + feature options/CPTs/tables | Quick view, wishlist, compare, filters, swatches, badges, waitlist, etc. |

Plan names in the `plans` column match the Freemius plan slugs. `Any paid`
means the extension has no specific plan list; a valid paid licence is enough.

## Free vs Pro at a glance

### Free Blocksy theme

- All core customizer options: layout, colors, typography, sidebar, blog,
  single post/page, archives, search, 404, performance.
- Header/footer builder with the free theme items.
- WooCommerce core integration (when WooCommerce is active).
- Custom post type integration for existing public post types.
- Gutenberg/block editor support, page templates, breadcrumbs, share box.

### Free Blocksy Companion

- Sticky header and transparent header settings.
- Account header item.
- Conditions engine.
- Dynamic CSS file output (`dynamic_css_file`).
- Demo installer.
- Free extensions: Cookies Consent, Newsletter Subscribe, Product Reviews,
  Trending Posts.
- Header/footer additions that are not gated by the Pro layer.

### Blocksy Companion Pro

- Content Blocks: header/footer replacement, single/archive templates, 404,
  nothing-found, hooks, popups, maintenance.
- Extra header/footer sections and their conditions.
- Pro header items: contacts, content block, divider, language switcher,
  menu-tertiary, mobile-menu-secondary, search-input, widget-area-1.
- Pro footer items: menu-secondary, logo, button, text, content block,
  search-input, language switcher, contacts.
- Pro extensions listed above.
- White Label requires Agency; Shop Extra and Post Types Extra require
  Personal or higher.

### Builder item boundaries

| Area | Free theme | Free Companion | Pro |
| --- | --- | --- | --- |
| Header | top/middle/bottom rows, logo, menu, menu-secondary, search, button, text, socials, cart, mobile-menu, trigger, offcanvas, offcanvas-logo | account | contacts, content-block, divider, language-switcher, menu-tertiary, mobile-menu-secondary, search-input, widget-area-1 |
| Footer | top/middle/bottom rows, copyright, menu, socials, widget-area-1…6 | — | menu-secondary, logo, button, text, content-block, search-input, language-switcher, contacts |

## Extension settings details

### Cookies Consent (free)

Theme mods:

- `cookie_consent_type` — `type-1` / `type-2`
- `cookie_consent_period` — `onehour`, `oneday`, `oneweek`, `onemonth`,
  `threemonths`, `sixmonths`, `oneyear`, `forever`
- `cookie_consent_content`
- `cookie_consent_button_text`
- `cookie_consent_decline_button_text`
- `cookieMaxWidth`
- `forms_cookie_consent_content`
- Colors: `cookieBackground`, `cookieContentColor`, `cookieButtonText`,
  `cookieButtonBackground`, `cookieDeclineButtonText`,
  `cookieDeclineButtonBackground`

### Newsletter Subscribe (free)

Theme mods: `newsletter_subscribe_title`, `newsletter_subscribe_content`,
`newsletter_subscribe_button_text`, `has_newsletter_subscribe_name`,
`newsletter_subscribe_name_label`, `newsletter_subscribe_mail_label`,
`newsletter_subscribe_list_id`, `newsletter_subscribe_list_id_source`,
`newsletter_subscribe_subscribe_visibility`, container/input/button colors and
spacing.

Option: `blocksy_ext_mailchimp_credentials` (`api_key`, list data). The bridge
can read/write it, but never export it in a plan you share.

### Product Reviews (free)

- Option `blocksy_ext_product_reviews_settings`.
- CPT `blc-product-review`.
- Per-review meta `blocksy_product_review_options` with fields such as
  `product_review_entity`, `product_description`, `product_entity_sku`,
  `product_entity_brand`, `product_entity_price`, `product_link`, ratings.
- The CPT is registered by the extension; activate it before creating reviews.

### Trending Posts (free)

Theme mods: `trending_block_label`, `trending_block_location`,
`trending_block_conditions`, `trending_block_background`,
`trendingBlockFontColor`, `trendingBlockHeadingFont`,
`trendingBlockHeadingFontColor`, `trendingBlockArrowsColor`,
`trendingBlockImageRadius`, `trendingBlockContainerSpacing`.

`trending_block_location` is a Blocksy hook, e.g.
`blocksy:content:bottom`.

### Custom Code Snippets (Pro)

Theme mods (global):

- `header_scripts`
- `header_after_body_scripts`
- `footer_scripts`

Per-post meta uses the same keys inside `blocksy_post_meta_options`. Scripts are
output raw; the current user needs `unfiltered_html` for unrestricted HTML on
multisite. The bridge warns if that capability is missing.

### Color Mode Switch (Pro)

- Theme mod `darkColorPalette` — same 8-color shape as `colorPalette`.
- Header item `color-mode-switcher` values: `default_color_mode`
  (`light`/`dark`/`system`), `color_switch_icon_type`,
  `color_switch_icon_state`, `icon_size`, label visibility, icon colors.
- Logo item gains `dark_mode_logo`.

### Custom Fonts (Pro)

Option `blocksy_ext_custom_fonts_settings`:

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

The typography family key is `ct_font_<snake_case_name>`, e.g.
`ct_font_my_font`.

### Local Google Fonts (Pro)

Option `blocksy_ext_local_google_fonts_settings`:

```php
[
  'fonts' => [
    ['name' => 'Inter', 'variations' => ['n4', 'n7'], 'preloads' => ['variations' => ['n4']]],
  ],
  'cached_css' => '',
  'last_saved' => null,
  'urls' => [],
]
```

A raw option write is not enough: the extension downloads the CSS and font
files in three steps (`start_fonts_persistence`, `persist_fonts_for`,
`conclude_fonts_persistence`). Use the extension's dashboard UI or call those
methods; otherwise the option points at fonts that are not on disk.

### Adobe Fonts (Pro)

Option `blocksy_ext_adobe_typekit_settings`:

```php
[
  'project_id' => 'abc123',
  'fonts' => [
    ['name' => 'Proxima Nova', 'css_names' => ['proxima-nova'], 'variations' => [...]],
  ],
]
```

The extension fetches the font list from Adobe. Setting `project_id` alone may
not populate `fonts`; use the extension UI or its fetch method.

### Advanced Menu / Mega Menu (Pro)

Settings live on the nav menu item's `blocksy_post_meta_options`:

- `has_mega_menu` — `yes`/`no`
- `mega_menu_columns` — `1`…`6`
- `2_columns_layout`, `3_columns_layout`, etc. — grid templates
- `mega_menu_content_block` / content block selection keys
- Icon, badge, label, link, target, visibility, and item-level styles

Create the menu first, then set the item meta. The bridge's menu `items[].meta`
does this.

### Post Types Extra (Pro)

Option `blocksy_ext_post_types_extra_settings`:

```php
[
  'features' => [
    'read-time' => false,
    'dynamic-data' => false,
    'filtering' => false,
    'taxonomies-customization' => false,
  ],
]
```

Plan requirement: Personal or higher. It adds features to existing post types;
it does not create custom post types.

### Shortcuts Bar (Pro)

Theme mods: `shortcuts_bar_items`, `shortcuts_bar_type`,
`shortcuts_bar_visibility`, `shortcuts_bar_conditions`,
`shortcuts_container_*`, `shortcuts_item_color`, `shortcuts_icon_*`,
`shortcuts_font`, `shortcuts_divider*`, cart/compare badge colors.

Items are a `ct-layers`-style array with `id`, `enabled`, `label`, `link`,
`icon`, `visibility`, `link_target`, `link_nofollow`.

### Multiple Sidebars (Pro)

Option `blocksy_dynamic_sidebars_storage` is an array keyed by sidebar ID:

```php
[
  'promo' => [
    'id' => 'promo',
    'name' => 'Promo Sidebar',
    'conditions' => [
      ['type' => 'include', 'rule' => 'single_post', 'payload' => []],
    ],
  ],
]
```

The rendered sidebar ID is `ct-dynamic-sidebar-<id>`. Assign widgets to it with
the normal widget APIs/REST.

### White Label (Pro, Agency)

Option `blocksy_ext_white_label_settings`. Covers theme/plugin names,
thumbnails, icons, dashboard branding, hide billing/account, and related
client-facing labels. Agency plan only.

### Shop Extra (Pro, Personal+)

Requires WooCommerce. Option `blocksy_ext_woocommerce_extra_settings`:

```php
[
  'features' => [
    'floating-cart' => false,
    'quick-view' => false,
    'filters' => false,
    'wishlist' => false,
    'compareview' => false,
    'single-product-share-box' => false,
    'advanced-gallery' => false,
    'search-by-sku' => false,
    'free-shipping' => false,
    'variation-swatches' => false,
    'product-brands' => false,
    'product-affiliates' => false,
    'product-custom-tabs' => false,
    'product-size-guide' => false,
    'product-custom-thank-you-page' => false,
    'product-advanced-reviews' => false,
    'stock-scarcity' => false,
    'added-to-cart-popup' => false,
    'product-waitlist' => false,
    'suggested-products' => false,
  ],
  'product-brands-slug' => 'brand',
]
```

Some features create CPTs (`ct_size_guide`, `ct_thank_you_page`,
`ct_product_tab`) and database tables (waitlist, filters lookup). Activate the
extension before creating those posts.

## Version and tier detection

Never infer the tier from the extension name. Read `schema.extensions[].tier`
and `.plans`. The bridge derives them from the active plugin path
(`framework/extensions` vs `framework/premium/extensions`) and the Companion
preliminary config.
