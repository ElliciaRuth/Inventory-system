# Same PHP build as the production php_app container
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libxml2-dev oniguruma-dev libpng-dev libjpeg-turbo-dev \
        freetype-dev libwebp-dev libzip-dev git unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install mysqli pdo_mysql intl mbstring gd zip

# Composer is used by the one-time setup service
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/inventory.ini
