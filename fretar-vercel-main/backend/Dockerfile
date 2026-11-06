# Laravel em container na Vercel (FrankenPHP). Baseado no guia oficial da Vercel
# "Deploy Laravel on Vercel with Docker", com o driver do PostgreSQL adicionado.
FROM dunglas/frankenphp:1-php8.3-bookworm AS base

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions intl zip pdo_pgsql pgsql

WORKDIR /app

FROM base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY . .
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative

FROM base AS runtime

COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --chown=www-data:www-data Caddyfile /etc/frankenphp/Caddyfile

RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /config/caddy /data/caddy

# A API usa tokens (Sanctum): nao precisa de sessao nem cache em disco.
ENV PORT=80 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync

USER www-data

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
