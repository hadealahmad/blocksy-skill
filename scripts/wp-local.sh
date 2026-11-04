#!/usr/bin/env bash
#
# Run WP-CLI against a WordPress site:
#   - DDEV project (a .ddev/config.yaml in the site dir or a parent): runs WP-CLI
#     inside the web container via `ddev exec`, staging host files it needs.
#   - LocalWP site: discovers the Local MySQL socket.
#   - Anything else: plain wp --path=...
#
# Usage:
#   ./scripts/wp-local.sh /path/to/site/app/public option get template
#   ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/inspect.php
#
# Environment:
#   WP_CLI_BIN   Path or name of the WP-CLI binary (default: wp)
#   PHP_BIN      PHP binary to use (default: php)
#   BLOCKSY_DDEV Set to 0 to ignore DDEV and run the host wp binary.
#                In DDEV mode WP_CLI_BIN/PHP_BIN are ignored (the container's wp runs).
#
# On a normal server with a working wp-config.php, this simply runs wp --path=...
# and does not need the Local socket.

set -euo pipefail

if [ "${1:-}" = "" ]; then
	echo "Usage: $0 <wordpress-root> <wp-cli arguments...>" >&2
	exit 2
fi

SITE="$1"
shift

if [ ! -d "$SITE" ]; then
	echo "Not a directory: $SITE" >&2
	exit 2
fi

SITE="$(realpath "$SITE")"

if [ ! -f "$SITE/wp-config.php" ]; then
	echo "No wp-config.php in: $SITE" >&2
	exit 2
fi

# --- DDEV -------------------------------------------------------------------
DDEV_ROOT=""
if [ "${BLOCKSY_DDEV:-1}" != "0" ]; then
	D="$SITE"
	while [ "$D" != "/" ]; do
		if [ -f "$D/.ddev/config.yaml" ]; then
			DDEV_ROOT="$D"
			break
		fi
		D="$(dirname "$D")"
	done
fi

if [ -n "$DDEV_ROOT" ]; then
	if ! command -v ddev >/dev/null 2>&1; then
		echo "DDEV project found at $DDEV_ROOT but ddev is not installed (BLOCKSY_DDEV=0 to use host wp)" >&2
		exit 2
	fi

	if ! DESCRIBE="$(cd "$DDEV_ROOT" && ddev describe -j 2>&1)"; then
		echo "ddev describe failed in $DDEV_ROOT:" >&2
		echo "$DESCRIBE" | tail -5 >&2
		exit 2
	fi
	# Never auto-start: a first start after a DDEV upgrade can prompt `ddev poweroff`.
	if ! grep -q '"status_desc":"running"' <<<"$DESCRIBE"; then
		echo "DDEV project at $DDEV_ROOT is not running. Run 'ddev start' there (foreground), then retry." >&2
		exit 2
	fi

	SKILL_DIR="$(realpath "$(dirname "$(realpath "$0")")/..")"
	CONTAINER_ROOT="/var/www/html"
	STAGE=""
	STAGE_REL=".ddev/.blocksy-skill-$$"
	STAGED_N=0

	# Map a host file to a path the web container can read. Files inside the
	# project are already mounted; anything else is copied into a temp stage dir.
	# Sets MAPPED (no command substitution: the stage state must persist).
	to_container() {
		local f
		f="$(realpath "$1")"
		case "$f" in
			"$DDEV_ROOT"/*)
				MAPPED="$CONTAINER_ROOT/${f#"$DDEV_ROOT"/}"
				return
				;;
		esac
		if [ -z "$STAGE" ]; then
			STAGE="$DDEV_ROOT/$STAGE_REL"
			trap 'rm -rf "$STAGE"' EXIT
			mkdir -p "$STAGE/files"
			# inspect.php/apply.php require ../bridge/blocksy-bridge.php: keep the layout.
			cp -r "$SKILL_DIR/scripts" "$SKILL_DIR/bridge" "$STAGE/"
		fi
		case "$f" in
			"$SKILL_DIR"/scripts/* | "$SKILL_DIR"/bridge/*)
				MAPPED="$CONTAINER_ROOT/$STAGE_REL/${f#"$SKILL_DIR"/}"
				;;
			*)
				# Numbered subdir: two files with the same basename must not overwrite each other.
				STAGED_N=$((STAGED_N + 1))
				mkdir -p "$STAGE/files/$STAGED_N"
				cp "$f" "$STAGE/files/$STAGED_N/"
				MAPPED="$CONTAINER_ROOT/$STAGE_REL/files/$STAGED_N/$(basename "$f")"
				;;
		esac
	}

	# shortcut: any bare .php/.json arg that exists on the host is mapped (also a value
	# like `option update x composer.json`), --flag=<file> is not; refine if it bites.
	ARGS=()
	for A in "$@"; do
		if [[ "$A" == *.php || "$A" == *.json ]] && [ -f "$A" ]; then
			to_container "$A"
			ARGS+=("$MAPPED")
		else
			ARGS+=("$A")
		fi
	done

	# ddev exec does not forward host env: pass the WP-CLI adapter vars explicitly.
	# Whitelist only: BLOCKSY_TOKEN/APP_PASSWORD would otherwise land in argv (ps).
	ENVS=()
	for NAME in BLOCKSY_INSPECT BLOCKSY_INSPECT_PORTABLE BLOCKSY_INSPECT_POST_META BLOCKSY_DRY_RUN BLOCKSY_PLAN; do
		if [ -z "${!NAME+x}" ]; then
			continue
		fi
		VAL="${!NAME}"
		if [ "$NAME" = "BLOCKSY_PLAN" ] && [ -f "$VAL" ]; then
			to_container "$VAL"
			VAL="$MAPPED"
		fi
		ENVS+=("$NAME=$VAL")
	done

	# Sync even without staging: a plan written inside the project just now counts too.
	if grep -q '"mutagen_enabled":true' <<<"$DESCRIBE"; then
		(cd "$DDEV_ROOT" && ddev mutagen sync >/dev/null)
	fi

	# Run from the mapped site dir so WP-CLI finds wp-config.php there.
	WORKDIR="$CONTAINER_ROOT"
	if [ "$SITE" != "$DDEV_ROOT" ]; then
		WORKDIR="$CONTAINER_ROOT/${SITE#"$DDEV_ROOT"/}"
	fi
	set +e
	(cd "$DDEV_ROOT" && ddev exec --raw --dir "$WORKDIR" -- env ${ENVS[@]+"${ENVS[@]}"} wp "${ARGS[@]}")
	STATUS=$?
	set -e
	exit "$STATUS"
fi

WP_BIN="${WP_CLI_BIN:-wp}"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$WP_BIN" >/dev/null 2>&1; then
	echo "WP-CLI not found: $WP_BIN" >&2
	exit 2
fi

WP_REAL="$(command -v "$WP_BIN")"

RUN_DIR=""
SOCKET=""

if [ -d "$HOME/.config/Local/run" ]; then
	for CONF in "$HOME"/.config/Local/run/*/conf/nginx/site.conf; do
		[ -f "$CONF" ] || continue

		ROOT="$(grep -m1 -oP '^\s*root\s+"\K[^"]+' "$CONF" 2>/dev/null || true)"
		[ "$ROOT" = "$SITE" ] || continue

		# .../run/<id>/conf/nginx/site.conf -> .../run/<id>
		RUN_DIR="$(dirname "$(dirname "$(dirname "$CONF")")")"
		break
	done
fi

if [ -n "$RUN_DIR" ]; then
	if [ -f "$RUN_DIR/conf/mysql/my.cnf" ]; then
		SOCKET="$(grep -m1 -oP '^\s*socket\s*=\s*\K.*' "$RUN_DIR/conf/mysql/my.cnf" 2>/dev/null || true)"
	fi

	if [ -z "$SOCKET" ] && [ -f "$RUN_DIR/conf/php/php.ini" ]; then
		SOCKET="$(grep -m1 -oP '^mysqli\.default_socket\s*=\s*"?\K[^"]+' "$RUN_DIR/conf/php/php.ini" 2>/dev/null || true)"
	fi
fi

WP_ARGS=(--path="$SITE")

if [ "$(id -u)" -eq 0 ]; then
	WP_ARGS+=(--allow-root)
fi

if [ -n "$SOCKET" ] && [ -S "$SOCKET" ]; then
	exec "$PHP_BIN" -d "mysqli.default_socket=$SOCKET" "$WP_REAL" "${WP_ARGS[@]}" "$@"
fi

exec "$WP_BIN" "${WP_ARGS[@]}" "$@"
