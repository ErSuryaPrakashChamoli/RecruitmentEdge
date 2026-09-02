# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

########################################
# Stage 1: PHP dependencies (composer)
########################################
FROM php:${PHP_VERSION}-cli AS vendor

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
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        bcmath \
        exif \
        pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
FROM node:${NODE_VERSION}-alpine AS frontend

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
FROM php:${PHP_VERSION}-apache AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        mbstring \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        bcmath \
        exif \
        pcntl \
        opcache \
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
