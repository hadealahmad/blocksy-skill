# Blocksy skill

> Repository: <https://github.com/hadealahmad/blocksy-skill>

A portable OpenCode skill for configuring WordPress sites that run:

- Blocksy
- Blocksy Child
- Blocksy Companion
- Blocksy Companion Pro

It sets global theme options, the header/footer builder, color palettes,
typography, menus, per-page settings, Content Blocks/popups, and extensions.

## Why there are two adapters

| Access | Adapter |
| --- | --- |
| Shell / SSH / LocalWP / WP-CLI | `scripts/wp-local.sh` + `scripts/inspect.php` + `scripts/apply.php` |
| No shell, but plugin install allowed from wp-admin | `dist/blocksy-bridge.zip` + `scripts/remote.mjs` |
| No plugin install, wp-admin + REST only | Core REST for menus/pages/per-page meta (partial) |
| No automation | Generated plan + manual import / manual Content Blocks |

The bridge is a single-file plugin. It exposes a narrow, authenticated REST API
for Blocksy settings. That is what makes full configuration possible when there
is no file or database access.

## Install the skill

Clone it directly into the global skills directory:

```sh
git clone https://github.com/hadealahmad/blocksy-skill.git \
  ~/.config/opencode/skills/blocksy
```

Or copy this directory to `~/.config/opencode/skills/blocksy/`, or keep it as a
project skill in `.opencode/skills/blocksy/`. The skill entry point is
`SKILL.md`.

## Quick start with WP-CLI

```sh
SITE=/path/to/wordpress
./scripts/wp-local.sh "$SITE" eval-file scripts/inspect.php > current.json
BLOCKSY_INSPECT=export BLOCKSY_INSPECT_PORTABLE=1 \
  ./scripts/wp-local.sh "$SITE" eval-file scripts/inspect.php > backup.json
BLOCKSY_PLAN=examples/example-plan.json \
  ./scripts/wp-local.sh "$SITE" eval-file scripts/apply.php
```

`scripts/wp-local.sh` discovers the LocalWP MySQL socket automatically.

## Quick start with the bridge

1. Upload `dist/blocksy-bridge.zip` in **Plugins → Add New → Upload Plugin**.
2. Activate **Blocksy Bridge**.
3. **Settings → Blocksy Bridge** → copy the token.
4. Run:

```sh
export BLOCKSY_URL="https://example.com"
export BLOCKSY_TOKEN="..."

node scripts/remote.mjs info
node scripts/remote.mjs schema > schema.json
node scripts/remote.mjs export --portable --post-meta > backup.json
node scripts/remote.mjs apply examples/example-plan.json --dry-run
node scripts/remote.mjs apply examples/example-plan.json
```

Application Passwords are also supported:

```sh
export BLOCKSY_USER="admin"
export BLOCKSY_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
```

## Layout

```text
SKILL.md                  entry point and workflow
references/               adapters, storage, options, builder, content blocks,
                          extensions, menus, verification, plan format
scripts/
  wp-local.sh             LocalWP-aware WP-CLI wrapper
  inspect.php             WP-CLI schema/info/export
  apply.php               WP-CLI plan apply
  remote.mjs              Bridge REST client
bridge/
  blocksy-bridge.php      single-file bridge plugin source
dist/
  blocksy-bridge.zip      uploadable plugin
data/                     versioned option/builder catalogs
examples/
  example-plan.json       one end-to-end example
research/                 extraction notes and snapshots used to build this
```

## Safety

- The bridge requires an administrator (`manage_options`).
- **Pro gate:** when a request needs a Pro-only feature and the site has the free
  Companion build, stop and ask for Blocksy Companion Pro installation and
  licence activation. Do not fake or bypass it. See
  `references/pro-gate.md`.
- It refuses arbitrary options, arbitrary post types, arbitrary PHP, plugin
  installation, and user management.
- It never returns Freemius/licence secrets.
- Always export a backup before applying a plan.
- Always run `--dry-run` first.
- Prefer HTTPS. Rotate the bridge token if it leaks.
