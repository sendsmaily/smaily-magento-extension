#!/usr/bin/env bash
#
# bin/build-release-zip.sh — assemble the release ZIP (PRO-2470 release train).
#
# This is the ONE owner of what a shipped package contains: the release
# workflow (.github/workflows/release.yaml) and the packaging check
# (bin/verify-release-zip.sh) both call it, so the artifact CI publishes and
# the artifact the check inspects can never drift apart.
#
# Usage:
#   bin/build-release-zip.sh [output.zip]      (default: ./smaily-connect-magento2.zip)
#
# What stays out: the development apparatus (tests, CI, sandbox, tooling,
# static-analysis config), the Hyvä companion (compat/, published as the
# separate package smaily/module-connect-hyva), the internal working documents
# and the whole docs/ folder — it carries the engine contract vendored from a
# private repository and internal audits, so the documentation set lives on
# GitHub and the package links to it there (Erkki's decision, 2026-09-10).
# What ships alongside the code: README.md, CHANGELOG.md, LICENSE.txt.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-smaily-connect-magento2.zip}"
case "$OUT" in
    /*) ;;
    *) OUT="$PWD/$OUT" ;;
esac

rm -f "$OUT"
cd "$ROOT"

zip -q -r -X "$OUT" . \
    -x '.git/*' '.github/*' '.sandbox/*' '.vscode/*' '.claude/*' \
       'Test/*' 'compat/*' 'vendor/*' 'var/*' 'bin/*' \
       'docker-compose.yaml' 'Dockerfile' \
       'phpcs.xml.dist' 'phpstan.neon.dist' \
       'phpunit.xml.dist' 'phpunit.integration.xml.dist' \
       'composer.lock' '.gitignore' \
       'CONTRIBUTING.md' 'CLAUDE.md' 'STATUS.md' 'BACKLOG.md' \
       'PILOT_CHECKLIST.md' \
       'docs/*' \
       '*.zip' '*.zip.sha256'

echo "$OUT"
