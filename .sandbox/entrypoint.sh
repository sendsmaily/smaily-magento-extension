#!/usr/bin/env bash

set -e

# Wait for MySQL to start.
mysql_ready() {
    mysqladmin ping --host=$MYSQL_HOST --user=$MYSQL_USER --password=$MYSQL_PASSWORD > /dev/null 2>&1
}
while !(mysql_ready); do
    sleep 1
    echo "Waiting for MySQL to finish start up..."
done

# Wait for Elasticsearch to start.
elastic_ready() {
    curl -X GET "elastic:9200/_cat/nodes?v=true&pretty" > /dev/null 2>&1
}
while !(elastic_ready); do
    sleep 1
    echo "Waiting for Elasticsearch to finish start up..."
done

# Install sample data if requested.
if [ "${MAGENTO_SAMPLEDATA}" = "1" ]; then
    echo "Installing sample-data..."
    php -f /sample-data/dev/tools/build-sample-data.php -- --ce-source=/var/www/html
fi

# Ensure Magento is installed and up-to-date.
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
    --elasticsearch-host=elastic \
    --elasticsearch-port=9200 \
    --admin-firstname=Smaily \
    --admin-lastname=DevOps \
    --admin-email=${MAGENTO_ADMIN_EMAIL} \
    --admin-user=${MAGENTO_ADMIN_USERNAME} \
    --admin-password=${MAGENTO_ADMIN_PASSWORD}

exec docker-php-entrypoint "$@"
