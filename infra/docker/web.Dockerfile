# syntax=docker/dockerfile:1.7
#
# apps/web — Laravel + Inertia/Vue served by FrankenPHP (Caddy + PHP 8.4), non-root.
# Build context: repository root.
#   docker build -f infra/docker/web.Dockerfile -t platform-web .
# Roles (same image): web (default CMD), scheduler (`php artisan schedule:work`),
# migrations (`php artisan migrate --force`).

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm
ARG NODE_IMAGE=node:24-bookworm-slim
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node

# ------------------------------------------------------------------ base
# PHP runtime + extensions shared by the build and runtime stages.
FROM ${FRANKENPHP_IMAGE} AS base
RUN install-php-extensions pdo_pgsql pgsql redis intl zip gd bcmath opcache pcntl exif \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/docker/web/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
WORKDIR /app

# ------------------------------------------------------------------ build
# Composer deps without dev packages, Node toolchain for Vite (the Wayfinder
# plugin calls `php artisan`, so assets are built where PHP is available).
FROM base AS build
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    NPM_CONFIG_FUND=false \
    NPM_CONFIG_AUDIT=false \
    NPM_CONFIG_FETCH_RETRIES=5 \
    NPM_CONFIG_FETCH_RETRY_MINTIMEOUT=20000 \
    NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT=120000 \
    NPM_CONFIG_FETCH_TIMEOUT=900000

COPY apps/web/composer.json apps/web/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-progress

COPY apps/web/package.json apps/web/package-lock.json apps/web/.npmrc ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY apps/web/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && npm run build \
    && rm -rf node_modules public/hot storage/logs/*.log bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
       storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# ------------------------------------------------------------------ runtime
FROM base AS runtime
ARG APP_UID=10001
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN useradd --system --uid "${APP_UID}" --user-group --home-dir /app --shell /usr/sbin/nologin app \
    && mkdir -p /config/caddy /data/caddy /etc/frankenphp \
    && chown -R app:app /config /data

COPY infra/docker/web/Caddyfile /etc/frankenphp/app.Caddyfile
COPY --chmod=0755 infra/docker/web/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --from=build --chown=app:app /app /app

USER app
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:' . (getenv('PORT') ?: '8080') . '/up') === false ? 1 : 0);"]

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/app.Caddyfile"]
