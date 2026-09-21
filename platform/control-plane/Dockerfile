FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql

WORKDIR /app

COPY . /app

RUN mkdir -p /var/lib/dilibori/secrets /opt/platform/profiles \
    && chmod 700 /var/lib/dilibori/secrets

ENV APP_NAME=dilibori-control-plane \
    APP_DEBUG=false \
    PROFILES_PATH=/opt/platform/profiles \
    SECRETS_PATH=/var/lib/dilibori/secrets

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
