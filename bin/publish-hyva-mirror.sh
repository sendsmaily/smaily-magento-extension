#!/usr/bin/env bash
#
# bin/publish-hyva-mirror.sh — publish the Hyvä companion to its mirror
# repository, tagged with the release tag. (PRO-4020)
#
# Packagist reads smaily/module-connect-hyva from the mirror repository
# (sendsmaily/smaily-magento-hyva), which holds the companion at its root.
# This script commits the content of the verified companion ZIP
# (bin/verify-hyva-zip.sh) to the mirror's `main` branch and tags that commit
# with the release tag — the plain version, the same tag as Smaily Connect's.
# Every tag on the mirror is a Packagist version, so a published tag is never
# moved.
#
# Safe to run again for the same tag: when the mirror already has the tag
# with the same content, the script changes nothing; when the tag holds other
# content, it fails and pushes nothing. When `main` already holds the content
# (a run that pushed the commit but not the tag), the tag goes on that commit.
#
# Usage:
#   bin/publish-hyva-mirror.sh <tag> <mirror-url> <companion.zip>
#
# The release workflow runs it with an SSH deploy key loaded in an ssh-agent.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

if [ $# -ne 3 ]; then
    echo "usage: bin/publish-hyva-mirror.sh <tag> <mirror-url> <companion.zip>" >&2
    exit 2
fi
tag="$1"
remote="$2"
zip="$3"
case "$zip" in
    /*) ;;
    *) zip="$PWD/$zip" ;;
esac
if [ ! -f "$zip" ]; then
    echo "no companion ZIP at ${zip}" >&2
    exit 2
fi
source_commit="$( git -C "$ROOT" rev-parse HEAD )"

WORK="$( mktemp -d )"
trap 'rm -rf "$WORK"' EXIT

git clone --quiet "$remote" "$WORK/mirror" 2> "$WORK/clone.log" || { cat "$WORK/clone.log" >&2; exit 1; }
cd "$WORK/mirror"
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

if git rev-parse -q --verify refs/remotes/origin/main > /dev/null; then
    git checkout -q -B main origin/main
else
    git checkout -q --orphan main
fi
git rm -rq --ignore-unmatch -- .
unzip -q "$zip" -d .
git add -A
tree="$( git write-tree )"

if git ls-remote --exit-code --tags origin "refs/tags/${tag}" > /dev/null; then
    git fetch -q origin "refs/tags/${tag}:refs/tags/${tag}"
    if [ "$( git rev-parse "refs/tags/${tag}^{tree}" )" = "$tree" ]; then
        echo "ok    the mirror already has ${tag} with this content; nothing to publish"
        exit 0
    fi
    echo "The mirror already has the tag ${tag} with other content. A published tag is never moved; nothing was pushed." >&2
    exit 1
fi

if [ "$( git rev-parse -q --verify 'HEAD^{tree}' || true )" != "$tree" ]; then
    git commit -q -m "Smaily Connect ${tag}" \
        -m "The Hyvä companion module (compat/hyva) of sendsmaily/smaily-magento-extension at ${source_commit}."
fi
git tag -a "$tag" -m "Smaily Connect ${tag}"
git push -q --atomic origin main "refs/tags/${tag}"
echo "ok    published ${tag} to the mirror ($( git rev-parse --short HEAD ))"
