# Meridian dev/app image. Gives the app container the Postgres + Redis PHP extensions the
# Windows/Laragon host lacks (host PHP ships only pdo_mysql). Mirrors the CI PHP version (8.4).
FROM php:8.4-fpm-alpine

# Build deps for the PHP extensions below (removed from the final layer where possible).
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
        postgresql-dev \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk add --no-cache postgresql-libs icu-libs libzip \
    && apk del .build-deps

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# php-fpm listens on 9000 for the nginx "web" service.
EXPOSE 9000
CMD ["php-fpm"]
