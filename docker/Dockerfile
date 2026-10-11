# Meridian dev/app image. Gives the app container the Postgres + Redis PHP extensions the
# Windows/Laragon host lacks (host PHP ships only pdo_mysql). Mirrors the CI PHP version (8.4).
# gd (jpeg, png, webp — the scan types the app takes) is for the ink reader that reads a tick from its printed box
# (OcrInkReader, M160, D113); the testing server enables the same extension in its php.ini.
FROM php:8.4-fpm-alpine

# Build deps for the PHP extensions below (removed from the final layer where possible).
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk add --no-cache postgresql-libs icu-libs libzip libpng libjpeg-turbo libwebp \
    && apk del .build-deps

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# php-fpm listens on 9000 for the nginx "web" service.
EXPOSE 9000
CMD ["php-fpm"]
