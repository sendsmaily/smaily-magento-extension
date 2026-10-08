#!/usr/bin/env bash
#
# bin/collect-changelog.sh — print the changelog fragments in the order they
# landed on master, ready for the version cut.
#
# A pull request with a merchant-visible change adds one fragment,
# changelog.d/<ISSUE>.md, holding the one CHANGELOG bullet it would have
# written, instead of editing CHANGELOG.md: two pull requests then never
# touch the same file, and parallel pull requests stop conflicting on the top
# of the "Changes since …" list. The version-cut pull request moves every
# fragment into CHANGELOG.md, oldest first, and deletes the fragments
# (changelog.d/README.md has the format).
#
# A fragment's landing time is the commit that added it
# (git log --diff-filter=A); on master that is its squash-merge commit. A
# fragment that is not committed yet sorts last, after every landed one.
#
# Usage:
#   bin/collect-changelog.sh        the bullets, oldest first, one after another
#   bin/collect-changelog.sh -l     the fragment files in the same order, each
#                                   with its landing date (for the git rm step)
#
# Exit codes: 0 — printed (nothing to print is not an error);
#             1 — a fragment is not exactly one Markdown bullet ("- " on its
#                 first line, no second bullet), named on stderr;
#             2 — usage error.

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
DIR="$ROOT/changelog.d"

LIST_ONLY=0
case "${1:-}" in
    "") ;;
    -l) LIST_ONLY=1 ;;
    *)
        echo "usage: bin/collect-changelog.sh [-l]" >&2
        exit 2
        ;;
esac
if [ $# -gt 1 ]; then
    echo "usage: bin/collect-changelog.sh [-l]" >&2
    exit 2
fi

[ -d "$DIR" ] || exit 0

NOW="$( date +%s )"
ORDER="$( mktemp )"
trap 'rm -f "$ORDER"' EXIT

for file in "$DIR"/*.md; do
    [ -e "$file" ] || continue
    name="$( basename "$file" )"
    [ "$name" = "README.md" ] && continue
    # The oldest add wins when a fragment was deleted and added again.
    landed="$( git -C "$ROOT" log --diff-filter=A --format=%ct -- "changelog.d/$name" | tail -n 1 )"
    if [ -n "$landed" ]; then
        when="$( date -u -r "$landed" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d "@$landed" +%Y-%m-%dT%H:%M:%SZ )"
    else
        landed="$NOW"
        when="(not committed)"
    fi
    printf '%s\t%s\t%s\n' "$landed" "$name" "$when" >> "$ORDER"
done

PROBLEMS=0
# Same second (two fragments in one commit): the file name breaks the tie.
sort -t "$( printf '\t' )" -k1,1n -k2,2 "$ORDER" | while IFS="$( printf '\t' )" read -r _landed name when; do
    file="$DIR/$name"
    if [ "$LIST_ONLY" -eq 1 ]; then
        printf 'changelog.d/%s\t%s\n' "$name" "$when"
        continue
    fi
    # The fragment's text without leading and trailing blank lines.
    text="$( awk 'NF { started = 1 } started { print }' "$file" | awk '{ lines[NR] = $0 } NF { last = NR } END { for (i = 1; i <= last; i++) print lines[i] }' )"
    bullets="$( printf '%s\n' "$text" | grep -c '^- ' )"
    case "$text" in
        "- "*) ;;
        *) bullets=0 ;;
    esac
    if [ "$bullets" -ne 1 ]; then
        echo "changelog.d/$name: not exactly one Markdown bullet (\"- \" on its first line)" >&2
        exit 1
    fi
    printf '%s\n' "$text"
done || PROBLEMS=1

exit "$PROBLEMS"
