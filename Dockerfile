FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    zlib-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        intl \
        mbstring \
        mysqli \
        pdo_mysql \
        zip \
        opcache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/phpbb.ini

WORKDIR /var/www/phpbb
