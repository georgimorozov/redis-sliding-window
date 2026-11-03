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
# phpredis (pecl "redis"), the version docs/design.md "Supported versions" names; PHPIZE_DEPS builds it.
ARG PHPREDIS_VERSION=6.3.0
RUN apt-get update \
  && apt-get install -y --no-install-recommends git unzip $PHPIZE_DEPS \
  && printf '\n\n\n\n\n\n' | pecl install "redis-${PHPREDIS_VERSION}" \
  && docker-php-ext-enable redis \
  && php -r 'exit(phpversion("redis") === getenv("PHPREDIS_VERSION") ? 0 : 1);' \
  && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-autoloader
COPY . .
RUN composer dump-autoload
