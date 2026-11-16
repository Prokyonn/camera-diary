# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.13-php8.4-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions intl opcache pdo_sqlite

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-camera-diary.ini

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    LOCK_DSN=flock:///app/var/data/locks

WORKDIR /app

FROM base AS build

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
COPY addons ./addons
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build-only bin/console cache:warmup \
    && bin/console assets:install public

FROM base

COPY --from=build /app /app
