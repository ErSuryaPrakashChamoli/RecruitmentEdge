# syntax=docker/dockerfile:1

# Phase 8.10 (P810-OP-01, D8.10-004): every base image is pinned by version and digest, so the
# build is reproducible. composer.lock needs PHP >= 8.4.1 (symfony 8.1; vendor/composer/
# platform_check.php), so the runtime is PHP 8.5, the line the test suite runs on. To move to a
# newer patch release, change the tag AND the digest together, then run the full suite in the image.
ARG PHP_CLI_IMAGE=php:8.5.11-cli-trixie@sha256:19642e172d3a542225225e202ddc2c11f67bdcbddf147b676c49338609b9290f
ARG PHP_APACHE_IMAGE=php:8.5.11-apache-trixie@sha256:70d80539dcacae817d9a1320518b95c86bb9568835ef3a7a024d57a4898c90e4
ARG COMPOSER_IMAGE=composer:2.9.5@sha256:698d3801b2a622ace460c4743c781282fcbcb733a4cbf8b31c44731e846585e8
ARG NODE_IMAGE=node:22.22.1-alpine@sha256:8094c002d08262dba12645a3b4a15cd6cd627d30bc782f53229a2ec13ee22a00

FROM ${COMPOSER_IMAGE} AS composer-binary

########################################
# Stage 1: PHP dependencies (composer)
########################################
FROM ${PHP_CLI_IMAGE} AS vendor

# mbstring and pdo_sqlite are compiled into the official image; pdo_pgsql is not used.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        pdo_mysql \
        bcmath \
        exif \
        pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-binary /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

########################################
# Stage 2: Frontend assets (vite)
########################################
FROM ${NODE_IMAGE} AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources/ resources/
COPY public/ public/
RUN npm run build

########################################
# Stage 3: Runtime image (apache + php)
########################################
FROM ${PHP_APACHE_IMAGE} AS app

# mbstring, pdo_sqlite and (since PHP 8.5) opcache are compiled into the official image; opcache
# is configured in docker/php/local.ini. pdo_pgsql is not used.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libreoffice-writer-nogui \
        fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        pdo_mysql \
        bcmath \
        exif \
        pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data --from=vendor /app ./
COPY --chown=www-data:www-data --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
