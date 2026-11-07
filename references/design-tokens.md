# Design tokens for block-building skills

This file is for **any skill or agent that writes Gutenberg block markup** on a
Blocksy site, such as core blocks, GreenShift/GreenLight
(`wpsoul/greenlight-vibe`), Kadence, Spectra, Stackable, or a raw HTML block.
It explains how to style page content with Blocksy's palette, typography,
widths and classes instead of hard-coded values. When the customer changes
the palette or fonts in the Customizer, every page then follows.

The Blocksy skill provides the tokens. The block-building skill emits the
markup. Neither has to know the other's internals.

## 1. Get the live tokens

Always prefer the target site's live values over the snapshot in `data/`.

| Access | Command |
| --- | --- |
| WP-CLI | `BLOCKSY_INSPECT=tokens ./scripts/wp-local.sh "$SITE" eval-file scripts/inspect.php > blocksy-tokens.json` |
| Bridge | `node scripts/remote.mjs tokens > blocksy-tokens.json` |
| Neither | Fetch `https://SITE/wp-content/uploads/blocksy/css/global.css` (public when Blocksy writes CSS to a file, the default). Read the `:root{...}` blocks. Fall back to `data/css-variables.json` defaults. |

`blocksy-tokens.json` (`"schema": "blocksy-tokens/1"`) contains:

| Key | Contents |
| --- | --- |
| `palette[]` | `slug` (`palette-color-1`), `title`, `variable` (`--theme-palette-color-1`), `preset_variable` (`--wp--preset--color--palette-color-1`), resolved `value`, `classes.text/background/border`, `block_attr` |
| `font_sizes[]` | Gutenberg font-size presets: `slug`, `size`, `fluid`, `variable`, `class` |
| `layout` | `content_size`, `wide_size`, container/narrow widths, edge spacing, wide offset, content spacing, and the variable names for each |
| `variables` | Every `--*` defined at `:root`, per device (`desktop`/`tablet`/`mobile`) plus `resolved` (with `var()` chains followed) |
| `elements` | Blocksy's rules for plain elements: `h1`–`h6`, `body`, `figcaption`, `pre, code`, `.wp-block-pullquote`, forms… per device |
| `breakpoints` | `tablet_max` `999.98px`, `mobile_max` `689.98px`, ready-made `@media` strings, visibility classes |

Hand this file (or the parts you need) to the block-building skill as its
design system. It is read-only and contains no secrets.

## 2. Precedence: how to reference a token

Use the first option the block type supports:

1. **Block attributes with preset slugs** (core blocks and most block suites):
   `"textColor":"palette-color-3"`, `"backgroundColor":"palette-color-6"`,
   `"fontSize":"large"`. WordPress adds the matching classes
   (`has-palette-color-6-background-color`, `has-large-font-size`), and the
   editor shows the swatch as selected.
2. **Utility classes** on any element: `has-palette-color-1-color`,
   `has-palette-color-6-background-color`, `has-palette-color-5-border-color`,
   `ct-hidden-sm`, `alignwide`, `alignfull`.
3. **CSS variables** in custom CSS (GreenShift `styleAttributes`, a block's
   custom CSS, a section `<style>`): `var(--theme-palette-color-1)`,
   `var(--theme-text-color)`, `var(--theme-normal-container-max-width)`.
   Add the live value as a fallback when the CSS may run outside Blocksy,
   such as in the editor iframe of a different theme:
   `var(--theme-palette-color-1, #2872fa)`.

Never paste a resolved hex or pixel value from `blocksy-tokens.json` into
markup as a literal. The resolved values are there so you can choose contrast
and sizes; the markup should reference the variable.

`--wp--preset--color--palette-color-N` is registered by Blocksy as
`var(--theme-palette-color-N, <hex>)` (`inc/init.php`), so both names track
the Customizer palette. Prefer `--theme-palette-color-N` in hand-written CSS
because it is shorter and also works where presets are not printed.

## 3. Colors

The palette has eight slots. Blocksy's default roles are below; the user can
rename and re-colour them, so read `palette[].title` and `value` before
assuming a role.

| Slot | Variable | Default role | Typical use in sections |
| --- | --- | --- | --- |
| 1 | `--theme-palette-color-1` | Accent / primary | Buttons, links, highlights |
| 2 | `--theme-palette-color-2` | Accent hover | Hover states |
| 3 | `--theme-palette-color-3` | Body text | Paragraphs |
| 4 | `--theme-palette-color-4` | Headings / dark | Headings, dark section background |
| 5 | `--theme-palette-color-5` | Borders | Dividers, card borders |
| 6 | `--theme-palette-color-6` | Light surface | Alternating section background |
| 7 | `--theme-palette-color-7` | Lighter surface | Cards on light sections |
| 8 | `--theme-palette-color-8` | Base / white | Page background, text on dark |

Semantic variables point at palette slots and follow the user's Customizer
choices. Use them when you mean the role rather than a slot:

| Variable | Meaning |
| --- | --- |
| `--theme-text-color` | Body text |
| `--theme-headings-color`, `--theme-heading-1-color` … `--theme-heading-6-color` | Heading colors |
| `--theme-link-initial-color`, `--theme-link-hover-color` | Links |
| `--theme-border-color` | Default border |
| `--theme-selection-background-color`, `--theme-selection-text-color` | Text selection |
| `--theme-button-background-initial-color`, `--theme-button-background-hover-color` | Button background |
| `--theme-button-text-initial-color`, `--theme-button-text-hover-color` | Button text |

**Dark sections:** put `--theme-text-color`, `--theme-headings-color` and
`--theme-link-initial-color` overrides on the **section's own class**, for
example `.acme-hero{--theme-text-color:var(--theme-palette-color-8);--theme-headings-color:var(--theme-palette-color-8)}`.
Blocksy's headings and links then switch automatically inside that section.

## 4. Typography

- Use real `h1`–`h6`, `p`, `blockquote`, `figcaption` tags. Blocksy styles them
  through `--theme-font-family`, `--theme-font-size`, `--theme-font-weight`,
  `--theme-line-height`, `--theme-letter-spacing` set on those selectors (see
  `elements` in the tokens file).
- Do **not** set `font-family` on content. Inherit it, or use
  `var(--theme-font-family)`. Fonts are a Customizer decision (`rootTypography`,
  `h1Typography` … `h6Typography`).
- Sizes: prefer the Gutenberg presets from `font_sizes[]` (Blocksy ships
  `small` 13px, `medium` 20px, `large` 22→30px fluid, `x-large` 30→50px fluid,
  `xx-large` 45→80px fluid). They are `--wp--preset--font-size--<slug>` or
  the class `has-<slug>-font-size`.
- To make a block match a heading level without changing the tag, copy that
  heading's variables from `elements.h2.desktop` etc. rather than inventing
  numbers.

## 5. Layout and width

| Need | Use |
| --- | --- |
| Content width | `var(--theme-block-max-width)` (frontend: normal or narrow container width; editor: same) |
| Wide width | `var(--theme-block-wide-max-width)` = container + 2 × `--theme-wide-offset` |
| Site container | `var(--theme-normal-container-max-width)` (`maxSiteWidth`, default 1290px) |
| Narrow container | `var(--theme-narrow-container-max-width)` (`narrowContainerWidth`, default 750px) |
| Container + side gutters | `width: var(--theme-container-width)` or the class `ct-container` / `ct-container-narrow` |
| Vertical rhythm | `var(--theme-content-spacing)` (gap between blocks), `var(--theme-content-vertical-spacing)` (page top/bottom) |
| Full-bleed section | Block `"align":"full"` / class `alignfull` |
| Wide section | Block `"align":"wide"` / class `alignwide` |

Blocksy maps `theme.json` `contentSize`/`wideSize` to these variables, so
`--wp--style--global--content-size` and `--wp--style--global--wide-size` also
resolve correctly. Constrained-layout groups (`"layout":{"type":"constrained"}`)
follow them automatically.

### Pages built entirely from blocks

A landing page made of full-width sections usually needs per-page settings so
Blocksy does not wrap it in a sidebar, title hero or extra padding. Apply them
through this skill's plan (`post_meta`), not in the block markup:

```json
"post_meta": {
  "landing": {
    "page_structure_type": "type-4",
    "content_style_source": "custom",
    "content_style": "wide",
    "vertical_spacing_source": "custom",
    "content_area_spacing": "none",
    "has_hero_section": "disabled"
  }
}
```

`type-4` is the normal-width layout without a sidebar, and `type-3` is narrow.
`type-1` and `type-2` have a sidebar. Add `"disable_header": "yes"` or
`"disable_footer": "yes"` only if asked. Read the exact choices from the live
`schema` because they can differ between Blocksy versions.

## 6. Buttons, forms, borders

- Core Button blocks (`wp-block-button__link`, `wp-element-button`) already get
  Blocksy's button styles from `--theme-button-*`. For custom button markup,
  add `class="wp-element-button"` (or `ct-button`). For an outline button use
  the core Button block's `is-style-outline` style. Do not restyle buttons per
  page unless the design requires it. If it does, override `--theme-button-*`
  on the section class.
- Radius and shadow: `--theme-button-border-radius`, `--theme-form-field-border-radius`,
  `--theme-image-border-radius`, `--theme-box-shadow`. `--theme-border-radius`
  is component-scoped (it is not set at `:root`), so only use it with a fallback.
- Form fields follow `--theme-form-*`. Do not restyle inputs inside a page.

## 7. Responsive

- Breakpoints: tablet `max-width: 999.98px`, mobile `max-width: 689.98px`
  (desktop starts at 1000px, tablet at 690px). Use these exact values so page
  CSS switches at the same width as the header and the theme.
- Visibility classes: `ct-hidden-sm` (hide on mobile), `ct-hidden-md` (hide on
  tablet), `ct-hidden-lg` (hide on desktop).
- `variables.*.tablet` / `.mobile` in the tokens file show which root tokens
  change per device (for example `--theme-content-vertical-spacing`).

## 8. Rules for consumers

1. **Never redefine `--theme-*` on `:root` or `body`** from page CSS; that
   overrides the Customizer site-wide. Scope overrides to a class on your
   section.
2. Do not set `--has-*` variables. They are internal switches (`var(--true)` /
   `var(--false)`).
3. Do not reuse Blocksy's component classes (`ct-header`, `ct-footer`,
   `ct-panel`, `ct-sidebar`, `entry-card`, `[data-id=…]`) for page content.
   They carry layout JS and builder CSS. `data/css-classes.json` lists them.
   Only the families `color`, `layout`, `visibility` and `button` are meant to
   be reused.
4. Header and footer are not page content. Change them through this skill's
   builder plan (`references/builder.md`), not by placing blocks in a page.
5. Re-read the tokens after any palette or typography change. The variable
   names stay the same; only the resolved values change.

## 9. Mapping other systems onto Blocksy

Many block skills ship their own variable vocabulary with fallbacks. Point those
variables at Blocksy by defining them **on the page's outermost section/wrapper
class** (not `:root`), so the skill's markup stays unchanged.

### GreenLight / GreenShift (`wpsoul/greenlight-vibe`)

GreenLight writes `var(--wp--preset--…, fallback)` and
`var(--wp--custom--…, fallback)` and asks for variables on a parent class. On
Blocksy:

| GreenLight variable | Blocksy source |
| --- | --- |
| `--wp--style--global--content-size` | Already maps to `--theme-block-max-width`. Keep it. |
| `--wp--style--global--wide-size` | Already maps to `--theme-block-wide-max-width`. Keep it. |
| `--wp--custom--spacing--side` / `--wp--spacing--side` | Not set by Blocksy. Define it on the wrapper as `calc((100vw - var(--theme-container-width)) / 2)`, or leave the fallback |
| `--wp--preset--font-size--{small,medium,large,x-large,xx-large}` | Blocksy presets. Use them directly. |
| Other `--wp--preset--font-size--*` (`mini`, `l`, `god`…) | Not defined. The fallback applies. Prefer the five Blocksy slugs above. |
| `--wp--preset--spacing--*`, `--wp--custom--border-radius--*`, `--wp--preset--shadow--*`, `--wp--custom--transition--*` | Not defined by Blocksy. Fallbacks apply; that is fine. |
| Colors | Do not invent color variables. Use `var(--theme-palette-color-N)` or the semantic `--theme-*` colors from §3. |

GreenShift JSON attributes must escape `--` as `--`, so
`var(--theme-palette-color-1)` is written as
`var(--theme-palette-color-1)` inside `styleAttributes` and
`dynamicGClasses`. Plain CSS in `customCSS_Extra` or `<style>` does not need
this.

### Any other block suite

1. Check whether the suite's color picker shows the Blocksy palette. Blocksy
   registers it as `editor-color-palette`, so most suites list the eight
   colors and save the slug or `var(--wp--preset--color--palette-color-N)`.
   Use that.
2. If the suite stores raw values, put `var(--theme-palette-color-N)` in its
   custom-CSS/variable field instead of a hex.
3. Map the suite's typography to inherit, or to the five font-size presets.
4. Use `alignwide`/`alignfull` and Blocksy's breakpoints from §7.

## 10. Checklist before handing markup back

- [ ] No hex colors except as `var()` fallbacks.
- [ ] No `font-family` declarations on content.
- [ ] No `--theme-*` overrides at `:root`/`body`.
- [ ] Media queries use 999.98px / 689.98px.
- [ ] Full-width page has the §5 `post_meta` applied through a plan.
- [ ] Rendered page checked at desktop, tablet (≤999px) and mobile (≤689px).
