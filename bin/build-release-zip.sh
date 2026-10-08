#!/usr/bin/env bash
#
# bin/build-release-zip.sh — assemble the release ZIP (3.0.0-rc1 release train,
# 2026-09-10; its packaging follow-ups are PRO-2472).
#
# This is the ONE build of a shipped package: the release workflow
# (.github/workflows/release.yaml) and the packaging check
# (bin/verify-release-zip.sh) both call it, so the artifact CI publishes and
# the artifact the check inspects can never drift apart.
#
# Usage:
#   bin/build-release-zip.sh [output.zip]      (default: ./smaily-connect-magento2.zip)
#
# The archive is `git archive` of the committed tree (HEAD): a file that is
# not committed — a local .env, an IDE folder, a tool's local configuration,
# a stray build — can never ship, and an uncommitted change is not in it
# either. What stays out of the committed tree is listed once, as
# export-ignore in .gitattributes, which composer's dist installs from GitHub
# honour too: the development apparatus (tests, CI, sandbox, tooling,
# static-analysis config), the Hyvä companion (compat/, published as the
# separate package smaily/module-connect-hyva), the developer and internal
# working documents (TESTING.md, CONTRIBUTING.md, CLAUDE.md, STATUS.md) and
# the whole docs/ folder, the maintainers' working papers in docs/internal/
# included — it carries the engine contract vendored from a private
# repository and internal audits, so
# the documentation set lives on GitHub and the package links to it there
# (Erkki's decision, 2026-09-10), and the changelog fragments (changelog.d/)
# the next version cut moves into CHANGELOG.md. What ships alongside the code:
# README.md, CHANGELOG.md, LICENSE.txt.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-smaily-connect-magento2.zip}"
case "$OUT" in
    /*) ;;
    *) OUT="$PWD/$OUT" ;;
esac

rm -f "$OUT"
git -C "$ROOT" archive --format=zip -o "$OUT" HEAD

echo "$OUT"
