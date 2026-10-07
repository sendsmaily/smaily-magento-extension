#!/usr/bin/env bash

set -e

# Install sample data if requested.
if [ "${MAGENTO_SAMPLEDATA}" = "1" ]; then
    echo "Installing sample-data..."
    php -f /sample-data/dev/tools/build-sample-data.php -- --ce-source=/var/www/html
fi

# Ensure Magento is installed and up-to-date. Skip if already installed
# (app/etc/env.php present) — the persistent db-data/data volumes survive a
# container recreate, and re-running setup:install against an
# already-installed DB fails ("Trigger already exists").
if [ ! -f app/etc/env.php ]; then
    bin/magento setup:install \
        --base-url=${MAGENTO_URL} \
        --backend-frontname=${MAGENTO_BACKEND_FRONTNAME} \
        --language=${MAGENTO_LANGUAGE} \
        --timezone=${MAGENTO_TIMEZONE} \
        --currency=${MAGENTO_DEFAULT_CURRENCY} \
        --db-host=${MYSQL_HOST} \
        --db-name=${MYSQL_DATABASE} \
        --db-user=${MYSQL_USER} \
        --db-password=${MYSQL_PASSWORD} \
        --use-secure=0 \
        --base-url-secure= \
        --use-secure-admin=0 \
        --search-engine=opensearch \
        --opensearch-host=opensearch \
        --opensearch-port=9200 \
        --admin-firstname=Smaily \
        --admin-lastname=DevOps \
        --admin-email=${MAGENTO_ADMIN_EMAIL} \
        --admin-user=${MAGENTO_ADMIN_USERNAME} \
        --admin-password=${MAGENTO_ADMIN_PASSWORD}
fi

# The sandbox admin is reachable with the username and password above and
# nothing else — a browser or a script must never stop at a second factor.
# setup:install enables both two-factor modules, so switch them off here. This
# runs on every boot on purpose: it is a no-op once they are off, and it also
# repairs a data volume installed before this was here.
bin/magento module:disable Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth

exec docker-php-entrypoint "$@"
