FROM php:8.3-apache

ARG TASTYIGNITER_VERSION=4.0.1

RUN set -ex; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        unzip \
        openssl \
        curl \
        supervisor \
        libcurl4-openssl-dev \
        libjpeg-dev \
        libpng-dev \
        libxml2-dev \
        libonig-dev \
        libzip-dev \
        libicu-dev \
    ; \
    rm -rf /var/lib/apt/lists/*; \
    \
    docker-php-ext-configure gd --with-jpeg=/usr; \
    docker-php-ext-install -j$(nproc) pdo_mysql curl xml gd mbstring intl zip exif

RUN pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

RUN { \
        echo 'mysqli.allow_persistent = Off'; \
        echo 'pgsql.allow_persistent = Off'; \
    } > /usr/local/etc/php/conf.d/dilibori-connections.ini

RUN a2enmod rewrite headers

RUN echo "ServerName tasty" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

ENV TASTYIGNITER_VERSION=${TASTYIGNITER_VERSION}

RUN set -ex; \
    curl -o tastyigniter.zip -fSL "https://codeload.github.com/tastyigniter/TastyIgniter/zip/v${TASTYIGNITER_VERSION}"; \
    unzip tastyigniter.zip -d /usr/src/; \
    rm tastyigniter.zip; \
    mv /usr/src/TastyIgniter-${TASTYIGNITER_VERSION} /usr/src/tastyigniter

COPY app/.htaccess /usr/src/tastyigniter/

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /usr/src/tastyigniter && composer install --no-interaction --no-dev && \
    composer require tastyigniter/ti-ext-translate -W --no-interaction --update-no-dev

COPY app/extensions/dilibori /usr/src/tastyigniter/extensions/dilibori
COPY app/extensions/taconazo /usr/src/tastyigniter/extensions/taconazo
COPY platform/seeds /opt/platform/seeds

# App lives in the image so tenant containers do not copy onto bind mounts.
RUN mkdir -p /var/www/html \
    && cp -a /usr/src/tastyigniter/. /var/www/html/ \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

COPY app/supervisor/dilibori.conf /etc/supervisor/conf.d/dilibori.conf
COPY app/bin/provision.sh /usr/local/bin/provision.sh
COPY app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/provision.sh \
    && mkdir -p /var/log/supervisor \
    && chown -R www-data:www-data /usr/src/tastyigniter/storage /usr/src/tastyigniter/bootstrap/cache 2>/dev/null || true

USER root

LABEL org.opencontainers.image.title="dilibori-tastyigniter" \
      org.opencontainers.image.description="Immutable TastyIgniter image for multi-tenant PoC" \
      dilibori.tastyigniter.version="${TASTYIGNITER_VERSION}"

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
