---
name: blocksy
description: Use when configuring a WordPress site that runs the Blocksy theme, Blocksy Child, Blocksy Companion, or Blocksy Companion Pro — global theme options, header/footer builder, colors, typography, menus, Content Blocks/popups, per-page settings, extensions, custom fonts, and applying settings with WP-CLI or remote REST when there is no server access. Triggers on Blocksy, Blocksy Companion, Blocksy Pro, header builder, Content Blocks, custom fonts, Blocksy extensions, or Blocksy settings import/export.
license: MIT
---

# Blocksy

Configure a Blocksy site by writing a **plan** and applying it through one of
the adapters. The mechanics are stable; the option IDs come from the installed
Blocksy version.

## First decision: which adapter?

| Situation | Adapter | Coverage |
| --- | --- | --- |
| Shell / SSH / LocalWP / WP-CLI available | **WP-CLI** (`scripts/wp-local.sh`, `scripts/inspect.php`, `scripts/apply.php`) | Full |
| No shell, but can install a plugin from wp-admin | **Blocksy Bridge REST** (`dist/blocksy-bridge.zip`, `scripts/remote.mjs`) | Full |
| No plugin install, only wp-admin + REST | **Core REST fallback** | Menus, pages/posts, per-page Blocksy meta only |
| No automation possible | **Manual / assisted** | Generated plan + Customizer import / manual Content Blocks |

**The answer to "no server access" is the bridge plugin.** It is one
self-contained PHP file that exposes a narrow, authenticated REST API for
Blocksy settings. Install it once from wp-admin, then keep working over HTTPS
with a token or WordPress Application Password.

Read `references/adapters.md` before choosing.

## Non-negotiable rules

1. **The active theme owns the settings.** If Blocksy Child is active, theme
   options live in `theme_mods_blocksy-child`, not `theme_mods_blocksy`.
   Always write through `set_theme_mod()` or the bridge, never to a fixed option
   name.
2. **Use WordPress APIs for arrays.** `wp option update` and
   `wp post meta update` store JSON strings, not PHP arrays. Use
   `update_option()`, `set_theme_mod()`, `update_post_meta()` through
   `wp eval`/`wp eval-file`, or the bridge.
3. **Typography must be complete.** A `ct-typography` value needs `family`,
   `variation`, `size`, `line-height`, `letter-spacing`, `text-transform`, and
   `text-decoration`. A partial object produces warnings and broken CSS.
4. **Responsive values need all devices.** Use
   `{"desktop": ..., "tablet": ..., "mobile": ...}`. Do not rely on missing
   keys being filled.
5. **Content Blocks need conditions.** An empty `conditions` array means "never
   display". Selection is newest matching block first; avoid overlapping blocks
   for the same template type.
6. **Refresh the caches after a write.**
   `do_action('blocksy:dynamic-css:refresh-caches')` regenerates
   `uploads/blocksy/css/global.css`; `do_action('blocksy:cache-manager:purge-all')`
   clears page/object caches.
7. **Backup, then dry-run, then apply, then verify.** No full transaction
   rollback exists. Export current settings first; keep the backup until the
   site is verified.
8. **Check the free/pro tier before activating an extension.** Pro extensions
   are silently unavailable on the free plugin. See
   `references/extensions.md`.
9. **Stop at a Pro gate. Ask for Pro, do not bypass it.** If the request needs a
   Pro-only feature and the site has the free Companion build (or no valid
   licence), stop before composing/applying a plan and ask the user to install
   and activate Blocksy Companion Pro. Do not fake, patch, emulate, or silently
   substitute the feature. See `references/pro-gate.md`.

## Pro gate

This is the one rule that overrides "make it work":

- Free build detected (`companion_build: free`, `premium_code: false`,
  `plan: free`) and the request needs a Pro extension, Content Blocks, extra
  header/footer sections, Pro builder items, or Pro fonts → **stop**.
- Tell the user exactly what is Pro-only and that Pro replaces the free
  Companion plugin (only one can be active).
- Ask them to install and activate Blocksy Companion Pro and its licence.
- After they confirm, re-run `info`/`schema` and verify `companion_build: pro`,
  `premium_code: true`, and the requested extension is `available: true`.
- Then continue.
- If they decline, offer a free-scope alternative **only with explicit
  consent**, and label it as a different design, not a workaround.

Never write `blocksy_active_extensions` by hand to fake activation, never
activate both Companion plugins, never patch licence checks, and never recreate
a Pro feature with custom code. Full policy and feature list:
`references/pro-gate.md`.

## The plan format

A plan is a JSON object. Only include the sections you need.

```json
{
  "version": 1,
  "extensions": {
    "activate": ["cookies-consent"],
    "deactivate": []
  },
  "theme_mods": {
    "colorPalette": { "color1": { "color": "#2563eb" } },
    "header_placements": { "current_section": "type-1", "sections": [] }
  },
  "remove_theme_mods": [],
  "options": {
    "blocksy_ext_custom_fonts_settings": { "fonts": [], "stacks": [] }
  },
  "remove_options": [],
  "menus": [
    {
      "name": "Main Menu",
      "location": "menu_1",
      "replace": true,
      "items": [
        { "key": "home", "title": "Home", "type": "custom", "url": "/" }
      ]
    }
  ],
  "content_blocks": [
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
  ],
  "post_meta": {
    "sample-page": { "page_structure_type": "type-2" }
  },
  "remove_post_meta": {
    "sample-page": ["page_structure_type"]
  },
  "cache": true
}
```

Full reference: `references/plan-format.md`.
One working example: `examples/example-plan.json`.

## Workflow

### 1. Inspect

WP-CLI:

```sh
./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/inspect.php > current.json
```

Remote:

```sh
BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \
  node scripts/remote.mjs info

BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \
  node scripts/remote.mjs schema > schema.json
```

`info` tells you the active theme, whether the child is active, Blocksy and
Companion versions, licence plan, active extensions, CSS mode, and menu
locations. `schema` is the live catalog for **this** install.

### 2. Back up

```sh
# WP-CLI
BLOCKSY_INSPECT=export BLOCKSY_INSPECT_PORTABLE=1 BLOCKSY_INSPECT_POST_META=1 \
  ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/inspect.php > backup.json

# Remote
BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \
  node scripts/remote.mjs export --portable --post-meta > backup.json
```

Keep `backup.json` until the site is verified.

### 3. Compose the plan

Use the live schema for valid IDs, types, and defaults. Start from
`examples/example-plan.json` and edit it. See:

- `references/theme-options.md` for value shapes
- `references/builder.md` for header/footer placement structures
- `references/content-blocks.md` for template types, conditions, hooks, popups
- `references/extensions.md` for extension IDs, tiers, storage, and settings
- `references/menus.md` for menu items and locations

### 4. Dry-run, apply, verify

```sh
# WP-CLI dry-run
BLOCKSY_PLAN=plan.json BLOCKSY_DRY_RUN=1 \
  ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/apply.php

# WP-CLI apply
BLOCKSY_PLAN=plan.json \
  ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/apply.php

# Remote dry-run
BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \
  node scripts/remote.mjs apply plan.json --dry-run

# Remote apply
BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \
  node scripts/remote.mjs apply plan.json
```

A successful apply returns `ok: true`, the action list, warnings, errors, and
cache results. Then follow `references/verification.md`.

## Adapters in one page

### WP-CLI

Use `scripts/wp-local.sh`. It discovers the LocalWP MySQL socket automatically
by matching the site path in `~/.config/Local/run/*/conf/nginx/site.conf`. On a
normal server it falls back to plain `wp --path=...`.

```sh
./scripts/wp-local.sh <site-root> <wp command>
```

### Bridge REST

1. Upload `dist/blocksy-bridge.zip` in **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Open **Settings → Blocksy Bridge** and copy the token.
4. Use `scripts/remote.mjs` with `BLOCKSY_URL` and `BLOCKSY_TOKEN`.

Application Passwords are supported as the preferred auth. The token is the
fallback for hosts where the standard `Authorization` header is stripped
(common on minimal nginx/LocalWP configs) or app passwords are disabled. The
client sends it as `X-Blocksy-Bridge-Token`; set `BLOCKSY_TOKEN_QUERY=1` only if
a host strips custom headers too.

### Core REST fallback (no plugin install)

Core REST can handle menus and page/post content, and the theme exposes
`blocksy_meta` for per-page settings. It **cannot** set global theme mods, the
builder, extension options, or a Content Block's `template_type`. Use it only
for the subset it supports; use manual import for the rest.

### Manual / assisted

Generate the plan and either:

- import the theme options through **Customizer → General → Manage Options →
  Import**, or
- paste a generated PHP apply snippet into a code-snippets plugin, or
- create the Content Blocks and menus manually from the plan.

## What lives where

| Layer | Storage |
| --- | --- |
| Theme + child options | theme mods of the active stylesheet |
| Header/footer builder | `header_placements` / `footer_placements` theme mods |
| Active extensions | `blocksy_active_extensions` option |
| Extension dashboard settings | `blocksy_ext_*` options |
| Extension customizer settings | theme mods of the active theme |
| Content Blocks / popups / templates | `ct_content_block` posts + `template_type` meta + `blocksy_post_meta_options` meta |
| Per-page settings | `blocksy_post_meta_options` post meta |
| Menus | core WordPress menus + `nav_menu_locations` theme mod |
| CSS | `uploads/blocksy/css/global.css` + `blocksy_dynamic_styles_descriptor` transient |

Details: `references/storage-model.md`.

## Data files

| File | Contents |
| --- | --- |
| `data/theme-options.json` | 674 theme options for Blocksy 2.1.60: ID, type, default, responsive, section, choices |
| `data/builder-options.json` | 24 header + 20 footer items, 903 item options, 12 header + 8 footer section settings, default placements |
| `data/post-meta-options.json` | Per-post/page/blog meta options |
| `data/content-block-options.json` | Content Block options for all 9 template types |

These are **snapshots**. Always prefer the live `schema` from the target site.
Version differences are real; the extractor is part of the bridge and
`scripts/inspect.php`.

## Safety

- Only administrators can use the bridge. Do not expose the token in frontend
  code, commits, screenshots, or chat logs.
- Prefer Application Passwords over the query-string token in production.
- The bridge refuses arbitrary option keys, arbitrary post types, arbitrary PHP,
  plugin activation, and user management.
- Run `--dry-run` first. Read warnings. Check the returned action list.
- If a plan error mentions an unknown theme mod or option, the target site's
  Blocksy version or active extensions differ from the catalog. Re-inspect
  instead of guessing.

## References

| File | Read it for |
| --- | --- |
| `references/adapters.md` | WP-CLI, bridge REST, core REST, manual fallback, auth, installation |
| `references/plan-format.md` | Every plan field and its semantics |
| `references/storage-model.md` | Exact database/API location of every setting |
| `references/theme-options.md` | Control value shapes and global theme options |
| `references/builder.md` | Header/footer structure, item IDs, free/pro items |
| `references/content-blocks.md` | Template types, conditions, hooks, popups, maintenance |
| `references/extensions.md` | All 15 extensions, free/pro, plans, storage, settings |
| `references/pro-gate.md` | Stop/ask/verify policy when a Pro-only feature is requested on a free build |
| `references/menus.md` | Menus, items, locations, mega-menu meta |
| `references/verification.md` | Post-apply checks and cleanup |
