FROM php:7.2-apache

ENV MAGENTO_VERSION 2.2.11
ENV INSTALL_DIR /var/www/html
ENV COMPOSER_HOME /var/www/.composer

# Install Magento requirements.
RUN apt-get update \
    && apt-get install -y \
        git \
        libcurl3-dev \
        libfreetype6 \
        libfreetype6-dev \
        libicu-dev \
        libjpeg-dev \
        libmcrypt-dev \
        libmcrypt4 \
        libpng-dev \
        libxslt1-dev \
        libzip-dev \
        unzip \
        # MariaDB for mysqladmin ping in entrypoint
        mariadb-client \
    && pecl install mcrypt-1.0.3 \
    && docker-php-ext-enable mcrypt \
    && docker-php-ext-install bcmath \
    && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && docker-php-ext-install gd \
    && docker-php-ext-install intl \
    && docker-php-ext-install mbstring \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-install soap \
    && docker-php-ext-install xsl \
    && docker-php-ext-install zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install Composer.
RUN php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
    && EXPECTED_CHECKSUM="$(curl https://composer.github.io/installer.sig)" \
    && ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" \
    && test "$EXPECTED_CHECKSUM" = "$ACTUAL_CHECKSUM" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && chown www-data:www-data $COMPOSER_HOME

# Prepare server for Magento.
RUN a2enmod rewrite \
    && echo "memory_limit=2048M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Download and install Magento packages.
RUN su www-data -s /bin/bash -c "curl -L \"https://github.com/magento/magento2/archive/${MAGENTO_VERSION}.zip\" -o /tmp/magento2.zip \
    && unzip -q /tmp/magento2.zip -d /tmp \
    && mv /tmp/magento2-${MAGENTO_VERSION}/* /tmp/magento2-${MAGENTO_VERSION}/.htaccess $INSTALL_DIR \
    && composer install --no-dev"

# Download Magento sample-data.
RUN mkdir /sample-data \
    && chown www-data:www-data /sample-data \
    && su www-data -s /bin/bash -c "git clone https://github.com/magento/magento2-sample-data.git /sample-data \
    && git -C /sample-data checkout ${MAGENTO_VERSION}"

COPY ./.sandbox/entrypoint.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
