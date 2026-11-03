# Test images for scripts/docker-test.sh. Build context: the repository root (see .dockerignore).
ARG NODE_VERSION=24
ARG PHP_VERSION=8.4

FROM node:${NODE_VERSION}-trixie AS node-test
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY . .

FROM php:${PHP_VERSION}-cli-trixie AS php-test
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update \
  && apt-get install -y --no-install-recommends git unzip \
  && rm -rf /var/lib/apt/lists/*
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-autoloader
COPY . .
RUN composer dump-autoload
