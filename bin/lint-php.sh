#!/usr/bin/env bash
# Parses every PHP and PHTML file outside vendor/ with the php on PATH and
# fails naming each file it cannot parse. CI runs it on PHP 8.1, the oldest
# PHP the package supports: phpstan and phpcs do not flag newer syntax, and
# the unit job notices it only in files a test loads. (PRO-3735)
#
#   bin/lint-php.sh
#   docker run --rm -v "$PWD":/app -w /app php:8.1-cli bin/lint-php.sh
set -eo pipefail

cd "$(dirname "$0")/.."

failed=()
while IFS= read -r -d '' file; do
    if ! output="$(php -l "$file" 2>&1)"; then
        echo "$output"
        failed+=("$file")
    fi
done < <(find . \( -path ./vendor -o -path ./.git -o -path ./.claude \) -prune \
    -o -type f \( -name '*.php' -o -name '*.phtml' \) -print0)

version="$(php -r 'echo PHP_VERSION;')"
if [[ ${#failed[@]} -gt 0 ]]; then
    echo "PHP $version cannot parse ${#failed[@]} file(s):"
    printf '  %s\n' "${failed[@]}"
    exit 1
fi
echo "PHP $version parses every PHP and PHTML file."
