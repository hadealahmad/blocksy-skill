# Theme options

Blocksy theme options are WordPress theme mods. This page covers the value
shapes and the global option groups. For the full ID/default list, use the live
schema (`node scripts/remote.mjs schema` or `scripts/inspect.php`) or
`data/theme-options.json` for the 2.1.60 snapshot.

## Control value shapes

| Control type | Shape |
| --- | --- |
| `ct-switch` | `"yes"` / `"no"` (occasionally booleans when `behavior` is `bool`) |
| `ct-radio`, `ct-select`, `ct-image-picker` | Choice key string |
| `ct-number`, `ct-slider` | Number, or a unit string like `"60px"`; responsive: `{desktop,tablet,mobile}` |
| `ct-visibility` | `{desktop:true,tablet:true,mobile:false}`; if all devices match, Blocksy itself may store the scalar `true`/`false` |
| `ct-color-picker` | `{default:{color:"#..."}, hover:{color:"#..."}, ...}` |
| `ct-typography` | `{family, variation, size, "line-height", "letter-spacing", "text-transform", "text-decoration"}` |
| `ct-color-palettes-picker` | `{color1:{color:"#..."}, ..., color8:{color:"#..."}}` |
| `ct-border` | `{width, style, color:{color}}` |
| `ct-box-shadow` | `{inherit, blur, spread, v_offset, h_offset, inset, enable, color:{color}}` |
| `ct-background` | `{background_type, background_pattern, background_image:{attachment_id,x,y}, gradient, background_repeat, background_size, background_attachment, patternColor, overlayColor, backgroundColor}` |
| `ct-spacing` | `{state:1|2, values:[{value,unit} x4], custom:""}`; responsive: `{desktop:{...},tablet:{...},mobile:{...}}` |
| `ct-image-uploader` | Most: `{attachment_id: 123}`. Logo options use `inline_value`, so they store the attachment ID directly. |
| `ct-layers` | Array of layer objects: `{id, enabled, ...type-specific keys}` |
| `ct-header-builder` / `ct-footer-builder` | See `builder.md` |

### Sentinel values

Blocksy uses two literal strings inside many values:

- `CT_CSS_SKIP_RULE` — do not output this declaration.
- `CT_CSS_SKIP_RULEDEFAULT` — do not output, or fall back to the default.

Use them when a full object is required but a property should remain unset. Do
not use `null` in typography/color objects.

### Complete objects

`ct-typography` is the most common source of broken writes. Always send the full
object:

```json
{
  "family": "System Default",
  "variation": "n4",
  "size": "16px",
  "line-height": "1.65",
  "letter-spacing": "0em",
  "text-transform": "none",
  "text-decoration": "none"
}
```

`family` values:

- `"Default"` — theme default stack.
- A system font name, e.g. `"Georgia"`, `"monospace"`.
- A Google Font family, e.g. `"Inter"`.
- `ct_font_<snake_case_name>` for Custom Fonts, e.g. `ct_font_my_font`.
- `ct_typekit_<id>` for Adobe Fonts.
- A font stack variable, e.g. `var(--theme-font-stack-default)`.

`variation` values: `n1`…`n9` (weights 100–900) and `i1`…`i9` (italic). `n4` is
regular 400, `n7` is bold 700.

### Responsive values

If a control is marked `responsive: true`, send all devices:

```json
{ "desktop": 60, "tablet": 50, "mobile": 40 }
```

For spacing:

```json
{
  "desktop": { "state": 1, "values": [{"value":40,"unit":"px"}, ...], "custom": "" },
  "tablet":  { "state": 1, "values": [...], "custom": "" },
  "mobile":  { "state": 1, "values": [...], "custom": "" }
}
```

## Global option groups

The customizer groups (from the 2.1.60 snapshot):

| Group | Options | What it covers |
| --- | --- | --- |
| `general` | 117 | Layout, buttons, breadcrumbs, forms, content elements, social accounts, back-to-top, website frame |
| `header` | 1 | `header_placements` builder |
| `footer` | 1 | `footer_placements` builder |
| `sidebar` | 21 | Sidebar type, width, gap, sticky, visibility, colors, divider, shadow |
| `color` | 13 | Global palette, text/link/selection/border/heading colors |
| `typography` | 13 | Root, H1–H6, buttons, quote, pullquote, pre, figcaption |
| `performance` | 5 | Dynamic CSS output, emoji script, local gravatars, Google Fonts preconnect, lazy load |
| `blog_posts` | 72 | Blog archive: hero, page title, structure, columns, card, meta, pagination |
| `single_blog_posts` | 136 | Single post: hero, title, structure, content, meta, share, related, comments, nav |
| `archive_blog_posts_categories` | 61 | Category archives |
| `single_pages` | 74 | Pages |
| `author_page` | 61 | Author archives |
| `search_page` | 73 | Search results |
| WooCommerce | varies | Added only when WooCommerce is active |

Custom post types add `post_type_single_<slug>` and `post_type_archive_<slug>`
groups. Their option IDs use `<slug>_single_` / `<slug>_archive_` prefixes.

## Common global options

| ID | Type | Purpose |
| --- | --- | --- |
| `maxSiteWidth` | slider | Maximum site width (default 1290) |
| `contentAreaSpacing` | responsive slider | Vertical content spacing |
| `contentEdgeSpacing` | responsive slider | Container edge spacing |
| `narrowContainerWidth` | slider | Narrow container width (750) |
| `wideOffset` | slider | Wide alignment offset (130) |
| `colorPalette` | palette | The 8 global colors |
| `fontColor`, `linkColor`, `selectionColor`, `border_color`, `headingColor` | color | Global colors |
| `rootTypography`, `h1Typography`…`h6Typography` | typography | Global type scale |
| `buttons` | typography | Button type |
| `dynamic_css_file` | radio | `file` or `inline` |
| `has_back_top` | panel switch | Back-to-top panel |
| `has_passepartout` | panel switch | Website frame panel |
| `sidebar_type`, `sidebarWidth`, `sidebarGap`, `sidebar_visibility` | mixed | Global sidebar |
| `blog_structure`, `blog_columns`, `blog_card_type`, `blog_archive_order` | mixed | Blog archive |
| `single_blog_post_structure`, `single_blog_post_content_style`, `single_blog_post_related_order` | mixed | Single post |
| `single_page_structure`, `single_page_content_style` | mixed | Pages |
| `header_placements`, `footer_placements` | builder | Header/footer |

The live schema has the exact IDs and defaults for the installed version. Do not
assume the snapshot matches a different Blocksy release.

## Free vs Pro in global options

The core customizer options are part of the **free Blocksy theme**. Pro adds
features that plug into them:

- Custom fonts, local Google fonts, and Adobe fonts add font sources.
- Color Mode Switch adds `darkColorPalette` and the `color-mode-switcher` item.
- Content Blocks add header/footer replacement templates, single/archive
  templates, popups, maintenance pages, and hook blocks.
- Extra header/footer sections and their conditions are Pro.
- Sticky and transparent header settings are in **free Companion**.
- WooCommerce core integration is free; Shop Extra features are Pro.

## Reading and writing

WP-CLI:

```sh
# Read all mods as real JSON (do not use `wp theme mod get --format=json` for
# nested values; it flattens arrays badly)
wp eval 'echo json_encode(get_theme_mods());'

# Write
wp eval 'set_theme_mod("maxSiteWidth", 1290);'
```

After writing in the same PHP process, wipe Blocksy's in-memory cache before
regenerating CSS:

```php
blocksy_manager()->db->wipe_cache();
do_action('blocksy:dynamic-css:refresh-caches');
```
