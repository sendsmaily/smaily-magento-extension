#!/usr/bin/env bash
#
# bin/verify-release-zip.sh — build the release ZIP and prove it is shippable.
#
# The package is assembled by one exclusion list (export-ignore in
# .gitattributes, applied by bin/build-release-zip.sh, which the release
# workflow calls too), and an exclusion list is exactly the kind of thing
# that rots silently: a new development directory ships, or a rename drops a
# required file, and nobody notices until a merchant unpacks it. This script is the gate — it builds the artifact the same way CI does,
# then asserts what must be inside, what must not, that the version in the
# archive is the repo's, and that every shipped PHP file parses.
#
# Usage:
#   bin/verify-release-zip.sh [output.zip]   (default: ./smaily-connect-magento2.zip)
#
# Runs in CI on every push (.github/workflows/ci.yaml, job "package").

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
ZIP="${1:-smaily-connect-magento2.zip}"
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

bash "$ROOT/bin/build-release-zip.sh" "$ZIP" > /dev/null || {
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
require_glob() {
    if grep -qE "^$1$" "$LIST"; then
        pass "present: $1"
    else
        fail "missing: $1"
    fi
}

require registration.php
require composer.json
require etc/module.xml
require etc/db_schema.xml
require_glob 'i18n/.+\.csv'
require_glob 'view/.+'

# The documentation that ships with the code: what it is, what changed, and
# under which licence. Everything else lives on GitHub (see below).
require README.md
require CHANGELOG.md
require LICENSE.txt

# --- 2. What must never ship ------------------------------------------------
# The development apparatus (git metadata included — a git worktree has a
# `.git` pointer file instead of a directory), the separately published Hyvä
# companion, the developer and internal working documents (TESTING.md among
# them) and the whole docs/ folder — it vendors the engine
# contract from a private repository and carries internal audits, so the
# documentation set is linked on GitHub instead of copied into the package.
forbid() {
    local label="$1" pattern="$2" hits
    hits="$( grep -E "^${pattern}" "$LIST" | head -3 | tr '\n' ' ' )"
    if [ -n "$hits" ]; then
        fail "shipped ${label}: ${hits}"
    else
        pass "absent: ${label}"
    fi
}

forbid "tests"            'Test/'
forbid "Hyvä companion"   'compat/'
forbid "sandbox"          '\.sandbox/'
forbid "composer vendor"  'vendor/'
forbid "CI config"        '\.github/'
forbid "git metadata"     '\.git/'
forbid "git pointer file" '\.git$'
forbid "tooling scripts"  'bin/'
forbid "phpunit config"   'phpunit.*\.xml.*'
forbid "phpcs config"     'phpcs\.xml.*'
forbid "phpstan config"   'phpstan\.neon.*'
# Any dot-file or dot-folder, at any depth: .env, .gitignore, .gitattributes,
# IDE folders (.idea/, .vscode/), .DS_Store, tool caches.
forbid "dot-files"        '(.*/)?\.'
forbid "composer lock"    'composer\.lock$'
forbid "archives"         '.*\.zip(\.sha256)?$'
forbid "docker files"     '(docker-compose.*|Dockerfile)$'
forbid "working status"   'STATUS\.md$'
forbid "backlog"          'BACKLOG\.md$'
forbid "agent guide"      'CLAUDE\.md$'
forbid "testing guide"    'TESTING\.md$'
forbid "pilot checklist"  'PILOT_CHECKLIST\.md$'
forbid "documentation"    'docs/'

# --- 3. The archive states the repo's version -------------------------------
repo_version="$( php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "";' "$ROOT/composer.json" )"
zip_version="$( unzip -p "$ZIP" composer.json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";' )"
if [ -z "$zip_version" ]; then
    fail "the archived composer.json states no version"
elif [ "$zip_version" != "$repo_version" ]; then
    fail "version mismatch: repo ${repo_version}, archive ${zip_version}"
else
    pass "version is ${zip_version}"
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
