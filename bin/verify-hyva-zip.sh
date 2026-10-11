#!/usr/bin/env bash
#
# bin/verify-hyva-zip.sh — build the Hyvä companion ZIP and prove it is
# shippable. (PRO-4020)
#
# The companion counterpart of bin/verify-release-zip.sh: it builds the
# archive the same way the release workflow does (bin/build-hyva-zip.sh),
# then asserts what a Magento module needs to install, that the composer.json
# names the companion package and states no version (Packagist takes it from
# the release tag), that no dot-file ships, and that every PHP file parses.
#
# Usage:
#   bin/verify-hyva-zip.sh [output.zip]   (default: ./smaily-connect-magento2-hyva.zip)
#
# Runs in CI on every push (.github/workflows/ci.yaml, job "package").

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
ZIP="${1:-smaily-connect-magento2-hyva.zip}"
case "$ZIP" in
    /*) ;;
    *) ZIP="$PWD/$ZIP" ;;
esac

FAILURES=0
fail() {
    echo "FAIL  $*"
    FAILURES=$(( FAILURES + 1 ))
}
pass() {
    echo "ok    $*"
}

bash "$ROOT/bin/build-hyva-zip.sh" "$ZIP" > /dev/null || {
    echo "!! the build step failed" >&2
    exit 2
}

WORK="$( mktemp -d )"
trap 'rm -rf "$WORK"' EXIT
LIST="$WORK/entries.txt"
unzip -Z1 "$ZIP" > "$LIST" || { echo "!! cannot list $ZIP" >&2; exit 2; }

echo "== $ZIP ($( wc -l < "$LIST" | tr -d ' ' ) entries)"

# --- 1. What a Magento module needs to install ------------------------------
require() {
    if grep -qx "$1" "$LIST"; then
        pass "present: $1"
    else
        fail "missing: $1"
    fi
}

require registration.php
require composer.json
require etc/module.xml

# --- 2. What must never ship ------------------------------------------------
hits="$( grep -E '^(.*/)?\.' "$LIST" | head -3 | tr '\n' ' ' )"
if [ -n "$hits" ]; then
    fail "shipped dot-files: ${hits}"
else
    pass "absent: dot-files"
fi

# --- 3. The composer.json Packagist reads -----------------------------------
name="$( unzip -p "$ZIP" composer.json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["name"] ?? "";' )"
if [ "$name" = "smaily/module-connect-hyva" ]; then
    pass "package is ${name}"
else
    fail "package name is '${name}', not smaily/module-connect-hyva"
fi
has_version="$( unzip -p "$ZIP" composer.json | php -r 'echo array_key_exists("version", json_decode(stream_get_contents(STDIN), true) ?? []) ? "yes" : "no";' )"
if [ "$has_version" = "no" ]; then
    pass "composer.json states no version (Packagist takes it from the tag)"
else
    fail "composer.json states a version; Packagist would skip a tag that disagrees with it"
fi

# --- 4. Every shipped PHP file parses ---------------------------------------
unzip -q "$ZIP" -d "$WORK/pkg"
php_files=0
lint_errors=0
while IFS= read -r file; do
    php_files=$(( php_files + 1 ))
    if ! php -l "$file" > "$WORK/lint.txt" 2>&1; then
        lint_errors=$(( lint_errors + 1 ))
        sed -n '1,2p' "$WORK/lint.txt" | sed "s#${WORK}/pkg/##"
    fi
done < <( find "$WORK/pkg" -name '*.php' -o -name '*.phtml' )
if [ "$lint_errors" -gt 0 ]; then
    fail "${lint_errors} of ${php_files} shipped PHP files do not parse"
else
    pass "php -l clean on all ${php_files} shipped PHP files"
fi

# --- 5. Build hash ----------------------------------------------------------
BUILD_HASH="$( sha256sum "$ZIP" | cut -d' ' -f1 )"
printf '%s  %s\n' "$BUILD_HASH" "$( basename "$ZIP" )" > "${ZIP}.sha256"

echo
echo "build hash (SHA-256): ${BUILD_HASH}"
echo "written to: ${ZIP}.sha256"
echo
if [ "$FAILURES" -gt 0 ]; then
    echo "VERIFY FAILED — ${FAILURES} problem(s); this ZIP must not be published."
    exit 1
fi
echo "VERIFY OK — $( basename "$ZIP" ) is shippable ($( wc -l < "$LIST" | tr -d ' ' ) entries)."
