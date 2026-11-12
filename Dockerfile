FROM dunglas/frankenphp:1-php8.4-bookworm AS base

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions bcmath gd intl mbstring opcache pdo_mysql pdo_pgsql zip

FROM base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

COPY . .

RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts \
    && composer dump-autoload --no-dev --optimize --no-interaction

FROM node:24-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY --from=dependencies /app /app
RUN npm run build

FROM base AS runtime

COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public /app/public
COPY Caddyfile /etc/frankenphp/Caddyfile

RUN setcap -r /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /config/caddy /data/caddy

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    TRUSTED_PROXIES=* \
    DB_CONNECTION=pgsql \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync

USER www-data

EXPOSE 10000

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
