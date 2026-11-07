# Blocksy CSS Tokens Extraction Notes

## Methodology & Extraction Scope
- **Script**: `research/extract-css-tokens.py` parses static files using Python 3 standard library only.
- **Scanned Sources**:
  - CSS: `themes/blocksy/static/bundle/*.min.css` (24 files; skipping RTL, 3rd-party integrations, and admin/options controls), Companion & Companion Pro non-admin bundles (6 files), and `uploads/blocksy/css/global.css`.
  - PHP: `themes/blocksy/inc/dynamic-styles` (25 files), `themes/blocksy/inc/panel-builder` (29 files), `blocksy-companion/framework` (159 files), `blocksy-companion-pro/framework` (527 files).
- **CSS Variables**: Extracted definitions (`--theme-*:`, PHP `variable`, `variableName`, `property`, `secondColorVariableName`, `$css->put`, typography helper outputs) and usages (`var(--theme-*)`). Correlated customizer `option_ids` via call-context AST parsing and backward assignment matching.
- **CSS Classes**: Filtered from non-integration theme bundles matching component prefixes (`^ct-`), palette utilities (`^has-.*palette`), Gutenberg layout primitives (`is-width-constrained`, `align*`, `entry-content`, `is-layout-*`), button primitives, responsive visibility (`ct-hidden-*`), and block styles (`is-style-*`).

## Extraction Counts
- **CSS Variables**: 154 variables extracted (`data/css-variables.json`).
  - Categories: `color` (49), `typography` (34), `layout` (16), `border` (15), `button` (11), `spacing` (7), `form` (8), `header` (4), `footer` (2), `shadow` (2), `other` (6).
- **CSS Classes**: 192 classes exported (`data/css-classes.json`).
  - Families: `component` (150 kept, capped from 190 total — 40 least frequent dropped), `color` (16), `layout` (10), `button` (8), `block-style` (5), `visibility` (3).

## Palette & Preset Facts (Answers a–d)
### a. Gutenberg Color Presets
- Blocksy registers its palette in `themes/blocksy/inc/init.php:51-60` via:
  ```php
  add_theme_support( 'editor-color-palette', apply_filters( 'blocksy:editor-color-palette', $gutenberg_colors ) );
  ```
- Blocksy does **not** hook `wp_theme_json_data_theme` (0 occurrences).
- Preset slugs: `palette-color-1` through `palette-color-8` (generated in `themes/blocksy/inc/classes/colors.php:593`).
- Resolution in PHP (`themes/blocksy/inc/init.php:48`):
  `'color' => 'var(--' . $palette['variable'] . ', ' . $palette['color'] . ')'`
  Resolves for `palette-color-1` to `var(--theme-palette-color-1, #2872fa)`.

### b. Gutenberg Font-size Presets
Defined in `themes/blocksy/theme.json:37-72` (`settings.typography.fontSizes`):
- `small`: `13px` (fluid: false)
- `medium`: `20px` (fluid: false)
- `large`: `30px` (fluid: min `22px`, max `30px`)
- `x-large`: `50px` (fluid: min `30px`, max `50px`)
- `xx-large`: `80px` (fluid: min `45px`, max `80px`)

### c. Block Max-Width Variables
- Defined in `themes/blocksy/theme.json:27-31` (`layout.contentSize` = `var(--theme-block-max-width)`, `layout.wideSize` = `var(--theme-block-wide-max-width)`).
- Block editor resolution (`themes/blocksy/inc/dynamic-styles/admin/editor.php:89-94`):
  `--theme-block-max-width: var(--theme-normal-container-max-width)`
  `--theme-block-wide-max-width: calc(var(--theme-normal-container-max-width) + var(--theme-wide-offset) * 2)`
- Frontend resolution (`themes/blocksy/static/sass/frontend/8-integrations/editors-common.scss:1-15`):
  - Normal content (`[data-content="normal"] > article`):
    `--theme-block-max-width: var(--has-wide, var(--theme-normal-container-max-width))`
    `--theme-block-wide-max-width: var(--has-wide, calc(var(--theme-normal-container-max-width) + var(--theme-wide-offset) * 2))`
  - Narrow content (`[data-content="narrow"] > article`):
    `--theme-block-max-width: var(--has-wide, var(--theme-narrow-container-max-width))`
    `--theme-block-wide-max-width: var(--has-wide, calc(var(--theme-narrow-container-max-width) + var(--theme-wide-offset) * 2))`
- Controlling Option IDs:
  - `maxSiteWidth` (`inc/dynamic-styles/global/all.php:298-302`, default `1290`) controls `--theme-normal-container-max-width`.
  - `narrowContainerWidth` (`inc/dynamic-styles/global/all.php:339-343`, default `750`) controls `--theme-narrow-container-max-width`.
  - `wideOffset` (`inc/dynamic-styles/global/all.php:345-349`, default `130`) controls `--theme-wide-offset`.
  - Content structure options (`page_content_area_structure`, `single_blog_post_content_area_structure`) set `data-content`.

### d. Breakpoints
- `theme.json:7-10` (`settings.viewport`): `"mobile": "689.98px"`, `"tablet": "999.98px"`.
- Distinct px breakpoints in `themes/blocksy/static/bundle/main.min.css`:
  - `max-width: 689.98px` (9): mobile upper limit
  - `min-width: 690px` (7): tablet lower limit
  - `max-width: 999.98px` (8): tablet upper limit
  - `min-width: 1000px` (7): desktop lower limit
  - `max-width: 600px` (3), `min-width: 601px` (1), `max-width: 782px` (1), `min-width: 783px` (1): WP admin-bar offsets
  - `max-width: 479.98px` (1): compact mobile layout

## Caveats & Notes
- Blocksy does not call `register_block_style()` in PHP; block styles like `is-style-dots`, `is-style-outline`, `is-style-plain`, `is-style-stripes`, and `is-style-wide` are pure CSS style variations.
- Some variables (e.g. `--has-boxed`, `--has-wide`, `--has-classic-forms`) act as binary toggle flags (`var(--true)` / `var(--false)`).
