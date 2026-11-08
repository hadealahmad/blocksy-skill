#!/usr/bin/env bash
# Tests the DDEV path mapping in scripts/wp-local.sh with a fake `ddev` (no Docker).
# Run: bash tests/wp-local-ddev.test.sh
set -euo pipefail

SKILL="$(realpath "$(dirname "$0")/..")"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

# Fake project: root with .ddev, WordPress in web/.
mkdir -p "$T/proj/.ddev" "$T/proj/web" "$T/bin" "$T/a" "$T/b"
touch "$T/proj/.ddev/config.yaml" "$T/proj/web/wp-config.php"
echo '{"in":1}' >"$T/proj/inside.json"
echo '{"a":1}' >"$T/a/plan.json"
echo '{"b":1}' >"$T/b/plan.json"

# Fake ddev: describe reports running; exec prints argv and the staged files, one per line.
cat >"$T/bin/ddev" <<'FAKE'
#!/usr/bin/env bash
case "$1" in
	describe) echo '{"raw":{"status_desc":"running","mutagen_enabled":false}}' ;;
	exec)
		shift
		printf 'ARG %s\n' "$@"
		find .ddev -path '*blocksy-skill-*/files/*' -type f -exec sh -c 'printf "FILE %s %s\n" "$1" "$(cat "$1")"' _ {} \;
		;;
esac
FAKE
chmod +x "$T/bin/ddev"

run() { (cd "$T" && PATH="$T/bin:$PATH" "$SKILL/scripts/wp-local.sh" "$T/proj/web" "$@"); }
FAIL=0
expect() { # expect <label> <output> <fixed string>
	if ! grep -qF -- "$3" <<<"$2"; then echo "FAIL: $1 (missing: $3)"; FAIL=1; fi
}

# 1. Plan outside the project is staged; env var is rewritten; secrets are not forwarded.
OUT="$(BLOCKSY_PLAN=a/plan.json BLOCKSY_TOKEN=secret run eval-file "$SKILL/scripts/apply.php")"
expect "workdir" "$OUT" "ARG /var/www/html/web"
expect "plan env mapped" "$OUT" 'ARG BLOCKSY_PLAN=/var/www/html/.ddev/.blocksy-skill-'
expect "plan copied" "$OUT" '/files/1/plan.json {"a":1}'
expect "skill script staged" "$OUT" '/scripts/apply.php'
if grep -q secret <<<"$OUT"; then echo "FAIL: BLOCKSY_TOKEN forwarded"; FAIL=1; fi

# 2. Plan inside the project is mapped, not copied.
OUT="$(run eval-file "$T/proj/inside.json")"
expect "inside mapped" "$OUT" "ARG /var/www/html/inside.json"
if grep -q '^FILE' <<<"$OUT"; then echo "FAIL: inside file was copied"; FAIL=1; fi

# 3. Two files with the same basename both survive.
OUT="$(run x a/plan.json b/plan.json)"
expect "first copy" "$OUT" '/files/1/plan.json {"a":1}'
expect "second copy" "$OUT" '/files/2/plan.json {"b":1}'

# 4. Stage dir is removed afterwards.
if ls -d "$T"/proj/.ddev/.blocksy-skill-* >/dev/null 2>&1; then echo "FAIL: stage dir left behind"; FAIL=1; fi

if [ "$FAIL" = 0 ]; then echo "OK"; fi
exit "$FAIL"
