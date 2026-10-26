#!/usr/bin/env bash
#
# Run WP-CLI against a WordPress site, automatically discovering the LocalWP
# MySQL socket when the site is a Local by Flywheel site.
#
# Usage:
#   ./scripts/wp-local.sh /path/to/site/app/public option get template
#   ./scripts/wp-local.sh /path/to/site/app/public eval-file scripts/inspect.php
#
# Environment:
#   WP_CLI_BIN   Path or name of the WP-CLI binary (default: wp)
#   PHP_BIN      PHP binary to use (default: php)
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
