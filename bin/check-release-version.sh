#!/usr/bin/env bash
#
# bin/check-release-version.sh — fail when a release tag is not the package
# version in composer.json. (PRO-3948)
#
# Every tag on the official repository is a Packagist version of
# smaily/smailyformagento, and Packagist skips a tag whose version disagrees
# with composer.json's "version". Without this check a mistyped tag still gets
# a release page and a ZIP that no composer store ever sees. The release
# workflow runs it first, before anything is built or attached.
#
# The tag is the PLAIN version (3.0.0-rc9, no "v" prefix), exactly as
# composer.json states it; anything else fails.
#
# Usage:
#   bin/check-release-version.sh <tag>
#
# Exit codes: 0 — the tag is the package version; 1 — it is not;
#             2 — usage error or composer.json states no version.

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

if [ $# -ne 1 ] || [ -z "$1" ]; then
    echo "usage: bin/check-release-version.sh <tag>" >&2
    exit 2
fi
tag="$1"

# shellcheck disable=SC2016 # $argv is PHP, not shell
version="$( php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "";' "$ROOT/composer.json" )"
if [ -z "$version" ]; then
    echo "composer.json states no version; cannot check the release tag '${tag}'." >&2
    exit 2
fi

echo "release tag: ${tag}; composer.json version: ${version}"
if [ "$tag" != "$version" ]; then
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
        echo "::error::Release tag '${tag}' does not match the package version '${version}'; nothing was uploaded."
    fi
    echo "Release tag '${tag}' does not match the package version '${version}' in composer.json." >&2
    echo "A release tag is the plain package version (Packagist skips a tag that disagrees with composer.json)." >&2
    echo "Delete this release and its tag, then publish it again tagged '${version}' (or fix the version cut first); nothing was built or uploaded." >&2
    exit 1
fi
echo "ok    the release tag is the package version"
