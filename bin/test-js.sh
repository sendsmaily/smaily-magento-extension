#!/usr/bin/env bash
# Runs the browser JS harnesses under Test/Js in headless Chrome and fails
# unless every page ends with "RESULT: PASS"; the last lines name each failed
# page and its failed checks. CI runs it (the browser job). The harnesses load
# Magento's own checkout JS and jQuery from vendor/, so run `composer install`
# first. The admin pages are rendered from their templates first
# (Test/Js/render-admin.php, into Test/Js/build/).
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

php "$root/Test/Js/render-admin.php"

status=0
failed=""
for page in "$root"/Test/Js/*.html; do
    name="${page#"$root"/}"
    # Virtual time runs the checkout's typing pauses without waiting for them.
    # A browser that prints nothing at all never ran the page (seen on macOS:
    # Chrome killed at start-up, exit 137, PRO-3780), so it is started again,
    # up to three starts; a page that printed anything is never run again.
    for attempt in 1 2 3; do
        output="$("$chrome" --headless=new --disable-gpu --no-sandbox \
            --virtual-time-budget=120000 --dump-dom "file://$page" 2>/dev/null || true)"
        [[ -z "$output" ]] || break
        echo "($name: the browser printed nothing, start $attempt of 3)" >&2
    done
    log="$(sed -n '/<pre id="log">/,/<\/pre>/p' <<<"$output" | sed -e 's/<[^>]*>//g' -e '/^$/d')"
    echo "== $name"
    [[ -z "$log" ]] || echo "$log"
    result="$(grep -o '<div id="result">[^<]*' <<<"$output" | sed 's/.*>//' || true)"
    result="${result:-RESULT: FAIL (no result)}"
    echo "$result"
    if [[ "$result" != "RESULT: PASS"* ]]; then
        status=1
        failed+="$name: $result"$'\n'
        failed+="$(grep '^FAIL ' <<<"$log" | sed 's/^/    /' || true)"$'\n'
    fi
done
if [[ "$status" -ne 0 ]]; then
    echo
    echo "Failed browser tests:"
    printf '%s' "$failed" | sed '/^$/d'
fi
exit "$status"
