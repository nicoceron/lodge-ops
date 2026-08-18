FROM php:8.5-cli-alpine

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        nodejs \
        npm \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install bcmath gd intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php-upload.ini /usr/local/etc/php/conf.d/inn-upload.ini
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY apps/api .
RUN npm ci \
    && npm run build \
    && mkdir -p /opt/inn-public-build \
    && cp -R public/build/. /opt/inn-public-build/ \
    && rm -rf node_modules \
    && apk del nodejs npm
RUN php artisan package:discover --ansi

EXPOSE 8000
