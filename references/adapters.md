# Adapters: with and without server access

The same plan format works through every adapter. Pick the strongest one the
site allows.

## Decision table

| What you have | Adapter | Can set global settings? | Can set builder? | Menus? | Content Blocks? | Per-page meta? |
| --- | --- | --- | --- | --- | --- | --- |
| Shell / SSH / WP-CLI / LocalWP | WP-CLI | Yes | Yes | Yes | Yes | Yes |
| wp-admin + can install a plugin | Bridge REST | Yes | Yes | Yes | Yes | Yes |
| wp-admin + REST only | Core REST | No | No | Yes | Partial | Yes |
| wp-admin only, no REST/plugin install | Manual / assisted | Via import | Via import | Manual | Manual | Manual |

## A. WP-CLI (server access)

Use `scripts/wp-local.sh`. It:

- resolves the site root,
- finds the LocalWP run directory by matching `conf/nginx/site.conf`,
- reads the MySQL socket from `conf/mysql/my.cnf` or `conf/php/php.ini`,
- runs `wp --path=<site>` with `mysqli.default_socket` when needed.

```sh
# Inspect
./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/inspect.php > current.json

# Dry-run
BLOCKSY_PLAN=plan.json BLOCKSY_DRY_RUN=1 \
  ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/apply.php

# Apply
BLOCKSY_PLAN=plan.json \
  ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/apply.php
```

On a normal server with a working `wp-config.php`, the socket discovery simply
does nothing and `wp --path` is used.

WP-CLI is the best option when available: no new plugin, no token, no HTTP
surface.

## B. Bridge REST (no server access, but plugin install allowed)

This is the full remote adapter.

### Install

With server access:

```sh
wp plugin install /path/to/dist/blocksy-bridge.zip --activate
```

With wp-admin only:

1. **Plugins → Add New → Upload Plugin**.
2. Upload `dist/blocksy-bridge.zip`.
3. Activate **Blocksy Bridge**.

### Authenticate

Preferred: **Application Password**.

1. **Users → Profile → Application Passwords**.
2. Create a password named `blocksy-skill`.
3. Use it with Basic auth over HTTPS.

Fallback: **bridge token**.

1. **Settings → Blocksy Bridge**.
2. Copy the token.
3. Send it as `X-Blocksy-Bridge-Token`, `Authorization: Bearer <token>`, or the
   `blocksy_bridge_token` query parameter.

Why the token exists: some minimal nginx/FastCGI configurations (including
LocalWP's default nginx config) do not forward the standard `Authorization`
header to PHP, so Application Passwords return 403. Custom `X-*` headers do
reach PHP on LocalWP; the query-string form is the last-resort fallback. Use
HTTPS and rotate the token if it leaks.

The client uses `X-Blocksy-Bridge-Token` by default. Set
`BLOCKSY_TOKEN_QUERY=1` if a host strips custom headers.

### Client

```sh
export BLOCKSY_URL="https://example.com"
export BLOCKSY_TOKEN="..."          # or:
# export BLOCKSY_USER="admin"
# export BLOCKSY_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"

node scripts/remote.mjs health
node scripts/remote.mjs info
node scripts/remote.mjs schema > schema.json
node scripts/remote.mjs export --portable --post-meta > backup.json
node scripts/remote.mjs apply plan.json --dry-run
node scripts/remote.mjs apply plan.json
node scripts/remote.mjs cache
```

### Endpoints

Base: `/wp-json/blocksy-bridge/v1`

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Public liveness check |
| GET | `/info` | Site, theme, Blocksy, licence, CSS, menus |
| GET | `/schema` | Live option/builder/content-block catalog |
| GET | `/export?portable=1&post_meta=1` | Export current settings |
| POST | `/apply?dry_run=1` | Validate/apply a plan |
| POST | `/cache` | Regenerate CSS and purge caches |

### What the bridge will not do

- No arbitrary PHP execution.
- No arbitrary option keys. Only `blocksy_*` settings in its allowlist.
- No plugin/theme installation or activation.
- No user management.
- No arbitrary post types. Content Blocks are restricted to
  `ct_content_block`.
- No license/secret export. `fs_*` options are never returned.
- No full database rollback. Use `export` before `apply`.

### Token/auth security

- The token grants administrator-level settings access. Treat it like a
  password.
- Do not put it in a URL that will be shared, committed, or screenshotted.
- Rotate it from **Settings → Blocksy Bridge** if exposed.
- The bridge logs the last apply in `blocksy_bridge_last_apply` (user, time,
  counts), not the token.

## C. Core REST only (no plugin install)

WordPress core already exposes some of what a Blocksy site needs:

| Capability | Endpoint | Notes |
| --- | --- | --- |
| Menus | `/wp/v2/menus` | Create/rename menus |
| Menu items | `/wp/v2/menu-items` | Create/update/delete items |
| Menu locations | `/wp/v2/menu-locations` | Assign a menu to a location |
| Pages/posts | `/wp/v2/pages`, `/wp/v2/posts` | Content, status, featured media |
| Per-page Blocksy meta | `blocksy_meta` field on supported post types | Theme provides this REST field |
| Block widgets | `/wp/v2/widgets`, `/wp/v2/widget-types` | Widget areas |
| Content Block posts | `/wp/v2/ct_content_block` | Post type is REST-enabled |
| Content Block conditions | `blocksy_meta` field | Conditions/template options are writable |
| Content Block `template_type` | **not exposed** | Cannot create a typed block remotely |
| Global theme mods | **no endpoint** | Not possible |
| Header/footer builder | **no endpoint** | Not possible |
| Extension options | **no endpoint** | Not possible |

Practical use: menus, pages, and per-page settings. Do not promise global
settings or typed Content Blocks through core REST.

## D. Manual / assisted (no automation)

Generate the plan and hand it to the site owner:

1. **Global theme options**: use the Blocksy Customizer's built-in export/import
   (General → Manage Options → Import). The import format is the Blocksy
   options bundle, not the plan JSON. The bridge's `export` can produce the
   values; the exact import format is `{template, mods, options,
   custom_palettes, wp_css}`.
2. **Menus**: create the menu in **Appearance → Menus** and assign the location.
3. **Content Blocks**: create a Content Block in **Blocksy → Content Blocks**,
   choose its type, paste the block markup, and set conditions.
4. **Per-page settings**: use the Blocksy metabox on the page.

A faster manual fallback: generate a one-time PHP snippet from the plan and run
it through a code-snippets plugin. The snippet is exactly what
`scripts/apply.php` does; it must run once and then be removed.

## Troubleshooting the remote adapter

| Symptom | Cause | Fix |
| --- | --- | --- |
| 404 on `/wp-json/blocksy-bridge/v1/health` | Plugin inactive, REST disabled, or permalinks broken | Activate the bridge; check `/wp-json/`; resave permalinks |
| 401/403 with an Application Password | `Authorization` header stripped by nginx/FastCGI, or app passwords disabled | Use `BLOCKSY_TOKEN` (header by default, `BLOCKSY_TOKEN_QUERY=1` if needed) |
| 403 with the token | Wrong token, token rotated, or a security plugin blocking the route | Re-copy from Settings → Blocksy Bridge; allowlist the route |
| 409 | Blocksy theme is not active | Activate Blocksy or the child theme |
| 413 | Plan over 2 MB | Split the plan or reduce content |
| 404 on a specific endpoint | Old bridge version | Update `dist/blocksy-bridge.zip` |
| `Content Blocks are not available` | Free Companion or no Pro licence | Use the builder or upgrade |
| REST requests blocked by a WAF | POST/JSON blocked | Allowlist `/wp-json/blocksy-bridge/*` or use WP-CLI |

A security plugin that disables REST for logged-out users is fine: the bridge
requires authentication. A plugin that disables REST entirely must be
configured to allow this namespace, or use WP-CLI.

## Security notes for the remote adapter

- The token and Application Passwords are administrator-equivalent. Treat them
  as secrets.
- Prefer Application Passwords on normal HTTPS hosts. Use the token when the
  Authorization header is stripped or app passwords are unavailable.
- The query-string token can appear in access logs. Rotate it if the logs are
  shared.
- The bridge only accepts its allowlisted options and post types. It cannot
  install plugins, edit users, or run arbitrary PHP.
- Revoke an Application Password in the user profile; rotate the token in
  Settings → Blocksy Bridge.
- Keep `blocksy_bridge_last_apply` for an audit trail (time, user, counts).

## E. Browser automation (last resort)

Automating the Customizer with a browser is possible but brittle: dynamic
controls, React state, nonces, and conditional fields change between versions.
Use it only when A–D are impossible, and verify every control after the run.
Prefer the bridge whenever a plugin can be installed.
