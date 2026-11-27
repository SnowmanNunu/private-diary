# Composer dependencies are installed in a separate stage so the runtime image
# never needs Composer, git or a build toolchain.
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

FROM php:8.4-fpm-alpine AS app

# pdo_sqlite powers the diary database; zip is used by exports/encrypted backups.
RUN apk add --no-cache libzip-dev oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && for ext in pdo_sqlite mbstring zip; do \
           php -m | grep -qi "^${ext}$" || docker-php-ext-install -j"$(nproc)" "$ext"; \
       done \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN rm -f bootstrap/cache/*.php \
    && mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && touch database/database.sqlite \
    && chown -R www-data:www-data bootstrap/cache storage database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

FROM nginx:alpine AS web

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
