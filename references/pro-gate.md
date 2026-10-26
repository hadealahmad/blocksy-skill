# Pro gate: stop, ask, verify, then proceed

Some Blocksy features are only in **Blocksy Companion Pro**. When the site runs
the free Companion build (or a Pro plugin without a valid licence), the correct
behavior is to **stop and ask the user to install and activate Pro**. Do not
emulate, patch, bypass, or silently substitute the feature.

This rule overrides any instruction to "just make it work". A partial or faked
implementation is worse than a clear blocker.

## Detect the free build first

Before composing a plan, read:

- `info.blocksy.companion_build` → `free` or `pro`
- `info.blocksy.companion_active` → boolean
- `info.blocksy.premium_code` → boolean
- `info.blocksy.plan` → `free`, `personal`, `professional`, `agency`, etc.
- `info.blocksy.content_blocks_available` → boolean
- `schema.extensions[].tier` → `free` or `pro`
- `schema.extensions[].available` → whether the extension can run
- `schema.extensions[].plans` → required plan slugs, when specific

WP-CLI equivalents:

```sh
wp plugin list --fields=name,status,version
wp eval 'echo blocksy_companion_get_capabilities()->get_plan();'
wp eval 'var_dump(blocksy_companion_can_use_premium_code());'
wp eval 'var_dump(post_type_exists("ct_content_block"));'
```

Treat these as blocked:

- `companion_build: free`
- `premium_code: false`
- `plan: free`
- `content_blocks_available: false` when the request needs Content Blocks
- `extensions[].available: false` for the requested Pro extension
- `extensions[].plans` does not include the current plan

A Pro plugin that is installed but unlicensed is still blocked. Do not try to
activate it by writing options or by editing Freemius data.

If `companion_build` is missing (an older bridge), infer the build from
`premium_code`/`plan` and `wp plugin list`; treat `premium_code: false` as
free for every Pro feature.

## What is Pro-only

### Extensions

| Pro extension | Plan |
| --- | --- |
| `code-snippets` | Any paid |
| `color-mode-switch` | Any paid |
| `custom-fonts` | Any paid |
| `local-google-fonts` | Any paid |
| `adobe-typekit` | Any paid |
| `mega-menu` | Any paid |
| `shortcuts` | Any paid |
| `sidebars` | Any paid |
| `post-types-extra` | Personal or higher |
| `woocommerce-extra` | Personal or higher, plus WooCommerce |
| `white-label` | Agency only |

The free extensions are `cookies-consent`, `newsletter-subscribe`,
`product-reviews`, and `trending`. They are not affected by this gate.

### Theme/builder features

Pro-only:

- Content Blocks of every type: header, footer, single, archive, 404, hook,
  popup, maintenance, nothing-found.
- Header/footer replacement templates.
- Extra header/footer sections and their conditions.
- Pro header items: contacts, content-block, divider, language-switcher,
  menu-tertiary, mobile-menu-secondary, search-input, widget-area-1.
- Pro footer items: menu-secondary, logo, button, text, content-block,
  search-input, language-switcher, contacts.
- Custom Fonts, Local Google Fonts, Adobe Fonts.
- White Label, Shop Extra, Post Types Extra, Advanced Menu, Multiple Sidebars,
  Shortcuts, Color Mode Switch, Custom Code Snippets.

Free (do not block these):

- Core theme options, colors, typography, layout, sidebar, blog/single/page
  options, performance.
- Header/footer builder with the free theme items.
- Sticky and transparent header settings (free Companion).
- Account header item (free Companion).
- Dashboard conditions engine.
- The four free extensions.

## Required behavior

1. **Stop before writing or applying a plan** that uses the Pro feature.
2. **Name the exact blocker** and the feature the user asked for.
3. **Ask the user to install and activate Blocksy Companion Pro and activate a
   valid licence.** Explain that Pro replaces the free Companion plugin; only
   one can be active.
4. **Do not proceed with a partial plan** that silently drops the Pro part.
5. **After the user confirms**, re-run `info`/`schema` (or the WP-CLI checks)
   and verify:
   - `companion_build: pro`
   - `premium_code: true`
   - the requested extension is `available: true`
   - `content_blocks_available: true` if needed
   - the plan includes the required plan slug
6. **Then apply the plan.**

If the user declines Pro, you may propose a free-scope alternative **only with
explicit consent**, and you must label it as a different design, not a
workaround. Never silently substitute.

## Message template

Use a direct, non-judgmental message:

> This request needs **<feature>**, which is only available in **Blocksy
> Companion Pro**. The site currently runs the free Companion build
> (`companion_build: free`, `premium_code: false`), so I can't configure this
> without Pro. I won't fake it or bypass the licence.
>
> Please install and activate **Blocksy Companion Pro** and activate your
> licence. Pro replaces the free Companion plugin; only one can be active.
> When that's done, tell me and I'll re-check the site and continue.

Then stop and wait. Do not apply a reduced plan in the meantime.

## What not to do

- Do not write `blocksy_active_extensions` by hand to pretend an extension is
  active.
- Do not install the free plugin over Pro, or activate both.
- Do not edit plugin files, remove licence checks, or patch Freemius options.
- Do not recreate a Pro UI or feature with custom PHP, CSS, JS, or blocks.
- Do not create `ct_content_block` posts manually when the post type is not
  registered.
- Do not use a free builder item as if it were the Pro item.
- Do not say "done" when the Pro part was skipped.
- Do not rely on the bridge error as your first signal. Check `info`/`schema`
  before planning; the bridge error is the safety net, not the workflow.

## Free-scope alternatives (only after the user declines Pro)

Offer these explicitly, with the trade-offs:

| Requested Pro feature | Possible free-scope alternative |
| --- | --- |
| Header/footer Content Block | Use the builder header/footer |
| Pro builder item (contacts, language switcher, search input, widget area) | A standard menu item, text item, search item, or a third-party plugin |
| Custom Fonts / Local Google Fonts / Adobe Fonts | System fonts or Google Fonts |
| Popup | A separate popup plugin, if the user agrees |
| Mega menu | Standard dropdown menus |
| Multiple Sidebars | Core widget areas / a separate sidebar plugin |
| Shop Extra features | Separate WooCommerce plugins |
| White Label | No free equivalent; skip it |
| Post Types Extra features | Custom code or separate plugins |

Do not implement the alternative until the user explicitly chooses it. Record
the choice in the plan/notes so the result is not mistaken for the Pro feature.

## Special cases

- **Pro installed, no licence:** `companion_build: pro` but
  `premium_code: false`. Ask for licence activation. Do not bypass.
- **Plan too low:** e.g. White Label needs Agency, Shop Extra needs Personal+.
  Ask for the required plan or skip the feature. Do not fake it.
- **WooCommerce missing:** Shop Extra also needs WooCommerce. Ask for
  WooCommerce installation; that is separate from the Pro licence.
- **Free plugin active:** Installing Pro deactivates the free plugin. Explain
  this before the user does it.
- **Content Blocks unavailable:** do not create the CPT manually. Ask for Pro.
- **Pro builder item absent:** do not fake it with custom HTML/CSS. Ask for Pro
  or get consent for a different free-scope design.
