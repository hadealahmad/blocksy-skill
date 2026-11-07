# Blocksy Builder Items Research

## Top-Level JSON Shape
File: `data/builder-items.json`
```json
{
  "blocksy_version": "2.1.60",
  "generated": "<ISO date>",
  "source": "static extraction of theme/companion/pro source; see research/builder-items.md",
  "counts": { "header": 27, "footer": 20, "missing_from_catalog": 3 },
  "items": [ /* sorted by builder then id */ ]
}
```

## Builder Items Inventory & Static Overlap

| id | builder | tier | requires | in_catalog | static | catalog | overlap |
|---|---|---|---|---|---|---|---|
| bottom-row | footer | free-theme | None | True | 23 | 22 | 95.5% |
| button | footer | free-theme | Pro (premium-footer.php:135) | True | 30 | 35 | 82.9% |
| contacts | footer | pro | Pro (premium-footer.php:141) | True | 27 | 23 | 95.7% |
| content-block | footer | pro | Pro (premium-footer.php:138) | True | 4 | 2 | 100.0% |
| copyright | footer | free-theme | None | True | 8 | 7 | 100.0% |
| language-switcher | footer | pro | Translation plugin + Pro (premium-footer.php:140) | True | 30 | 14 | 100.0% |
| logo | footer | free-theme | Pro (premium-footer.php:134) | True | 31 | 30 | 96.7% |
| menu | footer | free-theme | None | True | 11 | 10 | 100.0% |
| menu-secondary | footer | pro | Pro (premium-footer.php:16) | True | 11 | 10 | 100.0% |
| middle-row | footer | free-theme | None | True | 23 | 22 | 95.5% |
| search-input | footer | pro | Pro (premium-footer.php:139) | True | 37 | 36 | 97.2% |
| socials | footer | free-theme | None | True | 19 | 18 | 100.0% |
| text | footer | free-theme | Pro (premium-footer.php:136) | True | 18 | 15 | 100.0% |
| top-row | footer | free-theme | None | True | 23 | 22 | 95.5% |
| widget-area-1 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| widget-area-2 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| widget-area-3 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| widget-area-4 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| widget-area-5 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| widget-area-6 | footer | free-theme | None | True | 7 | 6 | 100.0% |
| account | header | free-companion | None (Pro overrides) | True | 63 | 57 | 96.5% |
| bottom-row | header | free-theme | None | True | 26 | 25 | 100.0% |
| button | header | free-theme | None | True | 30 | 33 | 81.8% |
| cart | header | free-theme | WooCommerce (cart/config.php:5) | True | 30 | 47 | 59.6% |
| color-mode-switcher | header | pro | ext:color-mode-switch (extension.php:95) | False | 18 | - | - |
| compare | header | pro | ext:woocommerce-extra + WooCommerce (extension.php:26) | False | 21 | - | - |
| contacts | header | pro | Pro (premium-header.php:78) | True | 27 | 21 | 95.2% |
| content-block | header | pro | Pro (premium-header.php:78) | True | 4 | 2 | 100.0% |
| divider | header | pro | Pro (premium-header.php:78) | True | 7 | 6 | 100.0% |
| language-switcher | header | pro | Translation plugin (config.php:7) | True | 30 | 27 | 85.2% |
| logo | header | free-theme | None | True | 31 | 28 | 96.4% |
| menu | header | free-theme | None | True | 36 | 36 | 97.2% |
| menu-secondary | header | free-theme | None | True | 36 | 36 | 97.2% |
| menu-tertiary | header | pro | Pro (premium-header.php:78) | True | 36 | 36 | 97.2% |
| middle-row | header | free-theme | None | True | 26 | 25 | 100.0% |
| mobile-menu | header | free-theme | None | True | 22 | 22 | 95.5% |
| mobile-menu-secondary | header | pro | Pro (premium-header.php:78) | True | 22 | 22 | 95.5% |
| offcanvas | header | free-theme | None | True | 18 | 21 | 76.2% |
| offcanvas-logo | header | free-theme | None | True | 5 | 4 | 100.0% |
| search | header | free-theme | None | True | 39 | 37 | 94.6% |
| search-input | header | pro | Pro (premium-header.php:78) | True | 37 | 34 | 97.1% |
| socials | header | free-theme | None | True | 22 | 21 | 100.0% |
| text | header | free-theme | None | True | 18 | 14 | 100.0% |
| top-row | header | free-theme | None | True | 26 | 25 | 100.0% |
| trigger | header | free-theme | None | True | 22 | 21 | 100.0% |
| widget-area-1 | header | pro | Pro (premium-header.php:78) | True | 1 | 1 | 100.0% |
| wish-list | header | pro | ext:woocommerce-extra + WooCommerce (extension.php:26) | False | 21 | - | - |

## Missing from catalog
All missing items are in the **header** builder; 0 footer items are missing:
1. `color-mode-switcher` (header, pro): Requires activating the **Color Mode Switch** extension (`color-mode-switch`) in Blocksy Companion Pro (`framework/premium/extensions/color-mode-switch/extension.php:95`).
2. `compare` (header, pro): Requires active **WooCommerce** (`plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/extension.php:26-38`), the **WooCommerce Extra** extension active, and the `compareview` feature flag enabled in extension settings (`extension.php:60-63`; registered in `features/compare/feature.php:20`).
3. `wish-list` (header, pro): Requires active **WooCommerce** (`plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/extension.php:26-38`), the **WooCommerce Extra** extension active, and the `wishlist` feature flag enabled in extension settings (`extension.php:53-56`; registered in `features/wish-list/feature.php:100`).

## Suspicious catalog entries
Items with <= 4 options in `data/builder-options.json`:
1. `content-block` (header & footer, 2 options: `content_width`, `margin`):
   - **Extraction artifact**: In `options.php:12-34`, options are wrapped in a ternary `empty(blocksy_companion_get_content_blocks()) ? [ ...HTML notice... ] : [ 'hook_id' => ... ]`. The extraction site had zero `ct_content_block` posts, so `'hook_id'` was never rendered into Customizer options, leaving only `content_width` (offcanvas-only) and `margin`.
2. `offcanvas-logo` (header, 4 options: `custom_logo`, `off_canvas_logo_max_height`, `inline_svg_logos`, `off_canvas_logo_margin`):
   - **Real count in core theme**: In `inc/panel-builder/header/offcanvas-logo/options.php:11-73`, exactly these 4 options exist statically. Additional options (e.g. dark mode offcanvas logo) are injected dynamically via filter `blocksy:panel-builder:offcanvas-logo:options:general` (`color-mode-switch/.../logo-enhancements.php:17`).
3. `widget-area-1` (header, 1 option: `widget`):
   - **Real count**: In `premium-header/items/widget-area-1/options.php:12-17`, the option definition is simply `'widget' => ['type' => 'ct-widget-area', 'sidebarId' => $sidebarId]`. Header widget areas do not expose dedicated alignment or link decoration settings unlike footer widget areas (`inc/panel-builder/footer/widget-area-1/options.php:10-128`), which define 6 options.
