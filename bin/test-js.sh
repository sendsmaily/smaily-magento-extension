#!/usr/bin/env bash
# Runs the storefront JS harnesses under Test/Js in headless Chrome and fails
# unless every page ends with "RESULT: PASS". The harnesses load Magento's own
# checkout JS from vendor/, so run `composer install` first.
#
#   bin/test-js.sh            # CHROME=/path/to/chrome to pick the browser
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
chrome="${CHROME:-}"
if [[ -z "$chrome" ]]; then
    for candidate in \
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
        google-chrome google-chrome-stable chromium chromium-browser; do
        if command -v "$candidate" >/dev/null 2>&1 || [[ -x "$candidate" ]]; then
            chrome="$candidate"
            break
        fi
    done
fi
if [[ -z "$chrome" ]]; then
    echo "No Chrome found; set CHROME=/path/to/chrome" >&2
    exit 2
fi

status=0
for page in "$root"/Test/Js/*.html; do
    # Virtual time runs the checkout's typing pauses without waiting for them.
    output="$("$chrome" --headless=new --disable-gpu --no-sandbox \
        --virtual-time-budget=120000 --dump-dom "file://$page" 2>/dev/null)"
    echo "== ${page#"$root"/}"
    sed -n '/<pre id="log">/,/<\/pre>/p' <<<"$output" | sed -e 's/<[^>]*>//g' -e '/^$/d'
    result="$(grep -o '<div id="result">[^<]*' <<<"$output" | sed 's/.*>//' || true)"
    result="${result:-RESULT: FAIL (no result)}"
    echo "$result"
    [[ "$result" == "RESULT: PASS"* ]] || status=1
done
exit "$status"
