#!/usr/bin/env bash
#
# bin/build-hyva-zip.sh — assemble the Hyvä companion ZIP. (PRO-4020)
#
# The Hyvä companion (compat/hyva, Magento module Hyva_SmailyConnect, composer
# package smaily/module-connect-hyva) is export-ignored from the main package,
# so a release publishes it on its own: this ZIP, laid out for
# app/code/Hyva/SmailyConnect the way the main ZIP is laid out for
# app/code/Smaily/Connect, and the same files as the mirror repository
# sendsmaily/smaily-magento-hyva that Packagist reads
# (bin/publish-hyva-mirror.sh unpacks this ZIP into it). The release workflow
# and bin/verify-hyva-zip.sh both call this script.
#
# Usage:
#   bin/build-hyva-zip.sh [output.zip]   (default: ./smaily-connect-magento2-hyva.zip)
#
# The archive is compat/hyva of the committed tree (HEAD). Its composer.json
# states no "version" (the build drops one if it comes back): the companion
# is versioned with Smaily Connect,
# so Packagist takes the version from the mirror's tag (the release tag), and
# a "version" that disagreed with the tag would make Packagist skip the tag.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-smaily-connect-magento2-hyva.zip}"
case "$OUT" in
    /*) ;;
    *) OUT="$PWD/$OUT" ;;
esac

WORK="$( mktemp -d )"
trap 'rm -rf "$WORK"' EXIT

git -C "$ROOT" archive --format=tar HEAD:compat/hyva | tar -x -C "$WORK"

# shellcheck disable=SC2016 # $argv is PHP, not shell
php -r '
    $file = $argv[1];
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    unset($data["version"]);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
' "$WORK/composer.json"

rm -f "$OUT"
( cd "$WORK" && zip -qrX "$OUT" . )

echo "$OUT"
