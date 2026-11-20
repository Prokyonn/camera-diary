# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.13-php8.4-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd intl opcache pdo_sqlite

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-camera-diary.ini

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    LOCK_DSN=flock:///app/var/data/locks

WORKDIR /app

FROM base AS build

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock symfony.lock ./
COPY addons ./addons
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build-only bin/console cache:warmup \
    && bin/console assets:install public

FROM base

COPY --from=build /app /app

# Runs as an unprivileged user (FrankenPHP may bind port 80 without root). It owns var/ (cache, the data volume) and
# Caddy's folders. A var/data written by an earlier root container needs `chown -R 1000:1000` once.
RUN useradd --uid 1000 --no-create-home --shell /usr/sbin/nologin app \
    && mkdir -p /app/var/data \
    && chown -R app:app /app/var /data /config
USER app
