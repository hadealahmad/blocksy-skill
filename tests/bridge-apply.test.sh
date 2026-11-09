#!/usr/bin/env bash
set -uo pipefail

if [ $# -lt 1 ] || [ -z "${1:-}" ]; then
	echo "Usage: $0 /path/to/wordpress" >&2
	exit 2
fi

SITE="$1"
SKILL="$(realpath "$(dirname "$0")/..")"
TMP_DIR="$(mktemp -d)"

ORIGINAL_RAW="$("$SKILL/scripts/wp-local.sh" "$SITE" eval 'echo wp_json_encode( get_theme_mod( "maxSiteWidth", "__missing__" ) );')"

cleanup() {
	if [ -n "${TMP_DIR:-}" ] && [ -d "$TMP_DIR" ]; then
		RESTORE_PLAN="$TMP_DIR/restore-plan.json"
		node -e '
			const fs = require("fs");
			const orig = JSON.parse(process.argv[1]);
			let plan;
			if (orig === "__missing__") {
				plan = { version: 1, remove_theme_mods: ["maxSiteWidth"] };
			} else {
				plan = { version: 1, theme_mods: { maxSiteWidth: orig } };
			}
			fs.writeFileSync(process.argv[2], JSON.stringify(plan));
		' "$ORIGINAL_RAW" "$RESTORE_PLAN"
		BLOCKSY_PLAN="$RESTORE_PLAN" "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" >/dev/null 2>&1 || true
		rm -rf "$TMP_DIR"
	fi
}
trap cleanup EXIT

PROBE="$(node -e '
	const orig = JSON.parse(process.argv[1]);
	console.log(orig === 1234 || orig === "1234" ? 1235 : 1234);
' "$ORIGINAL_RAW")"
PROBE_PLUS_1=$(( PROBE + 1 ))

FAILURES=0

# T1: preflight blocks partial write
T1_NAME="preflight blocks partial write"
T1_PLAN="$TMP_DIR/t1-plan.json"
node -e '
	const fs = require("fs");
	const probe = parseInt(process.argv[1], 10);
	fs.writeFileSync(process.argv[2], JSON.stringify({
		version: 1,
		theme_mods: {
			maxSiteWidth: probe,
			thisKeyDoesNotExist_xyz: 1
		}
	}));
' "$PROBE" "$T1_PLAN"

EC=0
BLOCKSY_PLAN="$T1_PLAN" "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t1-out.json" 2> "$TMP_DIR/t1-err.txt" || EC=$?

T1_POST_VAL="$("$SKILL/scripts/wp-local.sh" "$SITE" eval 'echo wp_json_encode( get_theme_mod( "maxSiteWidth", "__missing__" ) );')"

if [ "$EC" -ne 0 ] && node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	const postVal = JSON.parse(process.argv[2]);
	const origVal = JSON.parse(process.argv[3]);
	if (report.ok !== false) process.exit(1);
	if (report.preflight_failed !== true) process.exit(1);
	if (report.applied !== false) process.exit(1);
	if (JSON.stringify(postVal) !== JSON.stringify(origVal)) process.exit(1);
' "$TMP_DIR/t1-out.json" "$T1_POST_VAL" "$ORIGINAL_RAW"; then
	echo "PASS $T1_NAME"
else
	echo "FAIL $T1_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T2: partial typography rejected
T2_NAME="partial typography rejected"
T2_PLAN="$TMP_DIR/t2-plan.json"
node -e '
	const fs = require("fs");
	fs.writeFileSync(process.argv[1], JSON.stringify({
		version: 1,
		theme_mods: {
			rootTypography: {
				family: "System Default",
				size: "17px"
			}
		}
	}));
' "$T2_PLAN"

BLOCKSY_PLAN="$T2_PLAN" BLOCKSY_DRY_RUN=1 "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t2-out.json" 2> "$TMP_DIR/t2-err.txt" || true

if node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	if (report.ok !== false) process.exit(1);
	const errors = Array.isArray(report.errors) ? report.errors : [];
	const hasMissing = errors.some(e => typeof e === "string" && e.includes("is missing"));
	if (!hasMissing) process.exit(1);
' "$TMP_DIR/t2-out.json"; then
	echo "PASS $T2_NAME"
else
	echo "FAIL $T2_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T3: partial responsive rejected
T3_NAME="partial responsive rejected"
T3_PLAN="$TMP_DIR/t3-plan.json"
node -e '
	const fs = require("fs");
	fs.writeFileSync(process.argv[1], JSON.stringify({
		version: 1,
		theme_mods: {
			contentAreaSpacing: {
				desktop: "61px"
			}
		}
	}));
' "$T3_PLAN"

BLOCKSY_PLAN="$T3_PLAN" BLOCKSY_DRY_RUN=1 "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t3-out.json" 2> "$TMP_DIR/t3-err.txt" || true

if node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	if (report.ok !== false) process.exit(1);
	const errors = Array.isArray(report.errors) ? report.errors : [];
	const hasMissing = errors.some(e => typeof e === "string" && e.includes("is responsive and is missing"));
	if (!hasMissing) process.exit(1);
' "$TMP_DIR/t3-out.json"; then
	echo "PASS $T3_NAME"
else
	echo "FAIL $T3_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T4: complete responsive accepted
T4_NAME="complete responsive accepted"
T4_PLAN="$TMP_DIR/t4-plan.json"
node -e '
	const fs = require("fs");
	fs.writeFileSync(process.argv[1], JSON.stringify({
		version: 1,
		theme_mods: {
			contentAreaSpacing: {
				desktop: "61px",
				tablet: "61px",
				mobile: "51px"
			}
		}
	}));
' "$T4_PLAN"

BLOCKSY_PLAN="$T4_PLAN" BLOCKSY_DRY_RUN=1 "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t4-out.json" 2> "$TMP_DIR/t4-err.txt" || true

if node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	if (report.ok !== true) process.exit(1);
	if (!Array.isArray(report.errors) || report.errors.length !== 0) process.exit(1);
' "$TMP_DIR/t4-out.json"; then
	echo "PASS $T4_NAME"
else
	echo "FAIL $T4_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T5: atomic false applies valid part and refreshes cache
T5_NAME="atomic false applies valid part and refreshes cache"
T5_PLAN="$TMP_DIR/t5-plan.json"
node -e '
	const fs = require("fs");
	const probe = parseInt(process.argv[1], 10);
	fs.writeFileSync(process.argv[2], JSON.stringify({
		version: 1,
		atomic: false,
		theme_mods: {
			maxSiteWidth: probe,
			thisKeyDoesNotExist_xyz: 1
		}
	}));
' "$PROBE" "$T5_PLAN"

BLOCKSY_PLAN="$T5_PLAN" "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t5-out.json" 2> "$TMP_DIR/t5-err.txt" || true

T5_POST_VAL="$("$SKILL/scripts/wp-local.sh" "$SITE" eval 'echo wp_json_encode( get_theme_mod( "maxSiteWidth", "__missing__" ) );')"

if node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	const postVal = JSON.parse(process.argv[2]);
	const probe = parseInt(process.argv[3], 10);
	if (report.ok !== false) process.exit(1);
	if (report.applied !== true) process.exit(1);
	if (!report.cache || report.cache.dynamic_css !== true) process.exit(1);
	if (Number(postVal) !== probe) process.exit(1);
' "$TMP_DIR/t5-out.json" "$T5_POST_VAL" "$PROBE"; then
	echo "PASS $T5_NAME"
else
	echo "FAIL $T5_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T6: valid plan applies
T6_NAME="valid plan applies"
T6_PLAN="$TMP_DIR/t6-plan.json"
node -e '
	const fs = require("fs");
	const probeNext = parseInt(process.argv[1], 10);
	fs.writeFileSync(process.argv[2], JSON.stringify({
		version: 1,
		theme_mods: {
			maxSiteWidth: probeNext
		}
	}));
' "$PROBE_PLUS_1" "$T6_PLAN"

EC=0
BLOCKSY_PLAN="$T6_PLAN" "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/apply.php" > "$TMP_DIR/t6-out.json" 2> "$TMP_DIR/t6-err.txt" || EC=$?

if [ "$EC" -eq 0 ] && node -e '
	const fs = require("fs");
	const report = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	if (report.ok !== true) process.exit(1);
	if (report.applied !== true) process.exit(1);
' "$TMP_DIR/t6-out.json"; then
	echo "PASS $T6_NAME"
else
	echo "FAIL $T6_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

# T7: schema labels are English
T7_NAME="schema labels are English"
BLOCKSY_INSPECT=schema "$SKILL/scripts/wp-local.sh" "$SITE" eval-file "$SKILL/scripts/inspect.php" > "$TMP_DIR/t7-schema.json" 2> "$TMP_DIR/t7-err.txt" || true

if node -e '
	const fs = require("fs");
	const data = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
	const themeOptions = (data.schema && data.schema.theme_options) || [];
	const arabicRegex = /[\u0600-\u06FF]/;
	let arabicCount = 0;
	for (const opt of themeOptions) {
		if (typeof opt.label === "string" && arabicRegex.test(opt.label)) {
			arabicCount++;
		}
	}
	if (arabicCount !== 0) {
		console.error("Found " + arabicCount + " Arabic labels");
		process.exit(1);
	}
	const labelsLocale = data.schema && data.schema.site && data.schema.site.labels_locale;
	if (labelsLocale !== "en_US") {
		console.error("Expected labels_locale to be en_US, got: " + labelsLocale);
		process.exit(1);
	}
' "$TMP_DIR/t7-schema.json"; then
	echo "PASS $T7_NAME"
else
	echo "FAIL $T7_NAME"
	FAILURES=$(( FAILURES + 1 ))
fi

if [ "$FAILURES" -eq 0 ]; then
	echo "ALL PASS"
	exit 0
else
	exit 1
fi
