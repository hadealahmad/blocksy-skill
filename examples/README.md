# Example plan

`example-plan.json` is one end-to-end example. It touches every mechanism the
skill supports:

| Mechanism | What it does |
| --- | --- |
| `extensions` | Activates the free **Cookies Consent** extension |
| `theme_mods` | Sets an 8-color palette, root/H1/H2 typography, a sticky header, a footer, and cookie banner text |
| `menus` | Creates `Example Main Menu` with Home/Blog/About and assigns `menu_1` |
| `content_blocks` | Creates a **hook** block at `blocksy:content:top` on single posts |
| `post_meta` | Sets the Sample Page to the left-sidebar structure (`type-2`) |
| `cache` | Regenerates Blocksy CSS and purges caches |

It is intentionally small. It does not install plugins, delete content, or
change the site URL. The Content Block step requires **Blocksy Companion Pro**;
if the target site is free-only, remove the `content_blocks` section. The
Cookies Consent extension and the rest of the plan work on free Companion.

## Try it

WP-CLI:

```sh
SITE=/path/to/site/app/public

BLOCKSY_INSPECT=export BLOCKSY_INSPECT_PORTABLE=1 BLOCKSY_INSPECT_POST_META=1 \
  ./scripts/wp-local.sh "$SITE" eval-file scripts/inspect.php > /tmp/blocksy-backup.json

BLOCKSY_PLAN=examples/example-plan.json BLOCKSY_DRY_RUN=1 \
  ./scripts/wp-local.sh "$SITE" eval-file scripts/apply.php

BLOCKSY_PLAN=examples/example-plan.json \
  ./scripts/wp-local.sh "$SITE" eval-file scripts/apply.php
```

Remote:

```sh
export BLOCKSY_URL="https://example.com"
export BLOCKSY_TOKEN="..."

node scripts/remote.mjs export --portable --post-meta > /tmp/blocksy-backup.json
node scripts/remote.mjs apply examples/example-plan.json --dry-run
node scripts/remote.mjs apply examples/example-plan.json
```

## Verify

- Home page: new palette and typography, sticky header, `Example Main Menu`.
- Single post (`/hello-world/`): the Content Block notice appears at the top of
  the content.
- Sample Page (`/sample-page/`): left sidebar (`type-2`).
- Cookie banner: visible for logged-out visitors.
- `uploads/blocksy/css/global.css`: contains `--theme-palette-color-1` and the
  H1 size.

## Clean up

The plan is additive. To undo it, restore your backup, or — on a fresh test
site where the header/footer were default — apply this cleanup plan:

```json
{
  "version": 1,
  "extensions": { "deactivate": ["cookies-consent"] },
  "remove_theme_mods": [
    "colorPalette",
    "rootTypography",
    "h1Typography",
    "h2Typography",
    "header_placements",
    "footer_placements",
    "cookie_consent_type",
    "cookie_consent_period",
    "cookie_consent_content",
    "cookie_consent_button_text"
  ],
  "menus": [{ "name": "Example Main Menu", "delete": true }],
  "content_blocks": [{ "key": "example-single-notice", "delete": true }],
  "remove_post_meta": { "sample-page": ["page_structure_type"] },
  "cache": true
}
```
