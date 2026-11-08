# DDEV

A DDEV project is a full-access site: use the **WP-CLI adapter** through
`scripts/wp-local.sh`. It runs WP-CLI inside the web container, where the
database and PHP config are correct. The host `wp` binary cannot reach the DDEV
database (`wp-config-ddev.php` uses the container host `db`).

## How `wp-local.sh` handles DDEV

Pass the WordPress root on the host, exactly as for any other site:

```sh
# Pass the dir that holds wp-config.php (project root, or the docroot, e.g. web/)
./scripts/wp-local.sh ~/sites/acme eval-file scripts/inspect.php > current.json
```

The script:

1. walks up from the site dir to the first `.ddev/config.yaml` (project root);
2. refuses to run if the project is not running (it never runs `ddev start`);
3. maps host paths to the container: the project root is `/var/www/html`;
4. copies files the container cannot see (the skill's `scripts/` + `bridge/`,
   and a plan JSON outside the project) into `.ddev/.blocksy-skill-<pid>/`,
   deleted on exit;
5. forwards the adapter env vars (`BLOCKSY_INSPECT`, `BLOCKSY_INSPECT_PORTABLE`,
   `BLOCKSY_INSPECT_POST_META`, `BLOCKSY_DRY_RUN`, `BLOCKSY_PLAN`; `ddev exec`
   does not forward host env) and rewrites `BLOCKSY_PLAN` to its container path.
   Bridge credentials (`BLOCKSY_TOKEN`, `BLOCKSY_APP_PASSWORD`) are never passed;
6. runs `ddev exec --raw --dir <site dir in container> -- wp ...`, so WP-CLI
   finds `wp-config.php`.

`BLOCKSY_DDEV=0` forces the host `wp` (LocalWP/plain path) instead. In DDEV
mode `WP_CLI_BIN` and `PHP_BIN` are ignored.

Bare `.php`/`.json` arguments that exist on the host are mapped (even when
meant as a literal value). A `--flag=<file>` argument is passed through
unchanged; put such files inside the project.

## Starting the project

The script will not start a stopped project. Start it yourself, in the
foreground:

```sh
ddev version && ddev list   # check first
cd ~/sites/acme && ddev start
```

The first `ddev start` after a DDEV upgrade may ask to run `ddev poweroff`,
which stops **every** project. Do not background it or answer it blindly.

## Backup: use a DB snapshot

Rule 7 says there is no full rollback. On DDEV there is: snapshot the database
before applying.

```sh
cd ~/sites/acme
ddev snapshot --name blocksy-pre-$(date +%Y%m%d-%H%M)
# apply the plan ...
ddev snapshot --list
ddev snapshot restore blocksy-pre-20261104-1200   # roll back everything
```

Still take the JSON export (`BLOCKSY_INSPECT=export ...`). It is the portable
record of the settings and the only backup that moves to another environment.
After a snapshot restore, refresh the Blocksy CSS (see below), because
`uploads/blocksy/css/global.css` is a file, not a DB row.

## Paths in `info` output

`info.css.global_css_path` and other paths are **container** paths. Translate
`/var/www/html/` to the project root on the host (`ddev describe` →
`approot`).

| Project type | Uploads dir (host, relative to project root) |
| --- | --- |
| `wordpress`, docroot `""` | `wp-content/uploads/` |
| `wordpress`, docroot `web` | `web/wp-content/uploads/` |

Check the generated CSS from the host or the container:

```sh
grep -o 'palette-color-1:[^;]*' wp-content/uploads/blocksy/css/global.css
ddev exec grep -o 'palette-color-1:[^;]*' /var/www/html/wp-content/uploads/blocksy/css/global.css
```

## Cache refresh

```sh
./scripts/wp-local.sh ~/sites/acme eval \
  "do_action('blocksy:dynamic-css:refresh-caches'); do_action('blocksy:cache-manager:purge-all');"
```

With a Redis/Memcached add-on (`ddev add-on get ddev/ddev-redis`), also run
`ddev wp cache flush`.

## Importing a production database

Blocksy theme mods and `blocksy_post_meta_options` are serialized arrays that
contain absolute URLs (logos, background images). Replace URLs with WP-CLI,
which unserializes safely; never with `sed` on the dump.

```sh
ddev import-db --file=prod.sql.gz      # or: ddev pull <provider>
ddev wp search-replace 'https://example.com' "$(ddev describe -j | jq -r .raw.primary_url)" \
  --all-tables --precise --skip-columns=guid
```

Then refresh the CSS: `global.css` from production is not in the DB and the
local one still has the old values until regenerated.

Check the theme mods option name after an import: if production runs Blocksy
Child, settings live in `theme_mods_blocksy-child`, and the child theme must be
present locally too.

## Bridge REST against a DDEV site

Usually unnecessary (WP-CLI is available), but useful to test the bridge
locally before using it on a host without shell access.

- URL: `ddev describe` → primary URL (`https://<name>.ddev.site` by default).
- **TLS:** DDEV uses a mkcert certificate. curl and browsers trust it after
  `mkcert -install`; Node's `fetch` does not and fails with
  `UNABLE_TO_VERIFY_LEAF_SIGNATURE`. Point Node at the mkcert CA:

  ```sh
  export NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"
  BLOCKSY_URL=https://acme.ddev.site BLOCKSY_USER=admin \
    BLOCKSY_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
    node scripts/remote.mjs info
  ```

  Never use `NODE_TLS_REJECT_UNAUTHORIZED=0`.
- **Auth:** DDEV's default `nginx-fpm` forwards the `Authorization` header to
  PHP, so Application Passwords work (unlike LocalWP). The bridge token is not
  needed there. `apache-fpm` was not verified; fall back to the token on a 401.
- Install the bridge: `ddev wp plugin install /var/www/html/<path-in-project>/blocksy-bridge.zip --activate`
  (copy the zip into the project first; the container cannot see the skill
  directory).

## Mutagen (default on macOS and traditional Windows, not WSL2/Linux)

With `performance_mode: mutagen`, files reach the container through a sync.
The script runs `ddev mutagen sync` before every WP-CLI call, so a plan
written or staged a moment ago is visible. Uploads (`upload_dirs`) are bind-mounted, not synced:
`global.css` written by WP is on the host immediately.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `DDEV project at ... is not running` | Project stopped | `ddev start` in the project, foreground |
| `Error establishing a database connection` | Host `wp` used on a DDEV project (`BLOCKSY_DDEV=0`, or calling `wp` directly) | Use `wp-local.sh` without `BLOCKSY_DDEV=0`, or `ddev wp` |
| `Failed to execute command ... exit status N` | DDEV's wrapper line after a non-zero exit | Read the line(s) above it: that is the real error (e.g. Pro gate, unreadable plan) |
| `Plan file is not readable: /home/...` | Plan passed via a `--flag=` or other unmapped form | Pass it as `BLOCKSY_PLAN=<file>` or as a bare argument |
| Very slow WP-CLI runs | Xdebug on | `ddev xdebug off` |
| Node `fetch failed` / `UNABLE_TO_VERIFY_LEAF_SIGNATURE` | mkcert CA not trusted by Node | `NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"` |
