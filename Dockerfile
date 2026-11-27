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

# LibreOffice converts legacy Office documents (doc/xls/ppt) to PDF for the
# local preview feature. soffice runs headless; CJK fonts keep Chinese text
# from turning into tofu. The binary is wired up via LIBREOFFICE_PATH in compose.
# A wrapper resolves the real binary so we do not depend on the Alpine release's
# exact symlink layout (/usr/bin/libreoffice vs /usr/lib/libreoffice/program/soffice).
RUN apk add --no-cache libreoffice-impress libreoffice-writer libreoffice-calc \
        font-noto-cjk \
    && printf '%s\n' \
        '#!/bin/sh' \
        'for bin in /usr/bin/libreoffice /usr/bin/soffice /usr/lib/libreoffice/program/soffice; do' \
        '    [ -x "$bin" ] && exec "$bin" "$@"' \
        'done' \
        'echo "LibreOffice binary not found" >&2; exit 1' \
        > /usr/local/bin/soffice \
    && chmod +x /usr/local/bin/soffice \
    && (/usr/local/bin/soffice --version || true)

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
