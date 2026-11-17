ARG USER_ID=82
ARG GROUP_ID=82

# ---------------------------------------------------------------------------
# Composer dependencies (no dev), so the app image never needs Composer's dev tools at runtime.
# ---------------------------------------------------------------------------
FROM serversideup/php:8.5-fpm-nginx-alpine AS vendor

USER root
WORKDIR /var/www/html

# bcmath and gmp make brick/math (all money arithmetic) fast; intl is for number formatting.
RUN install-php-extensions bcmath gmp intl

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist --no-autoloader

# ---------------------------------------------------------------------------
# Front-end assets (Vue + Tailwind) built with vite-plus. No SSR bundle, so Node isn't needed at runtime.
# ---------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app
RUN corepack enable

# Baked into the bundle for the browser tab title; there's no .env at build time.
ARG VITE_APP_NAME=Sawirpixel
ENV VITE_APP_NAME=$VITE_APP_NAME

COPY package.json pnpm-lock.yaml .npmrc vite.config.js tsconfig.json ./
# Tailwind scans Laravel's pagination views in vendor for class names.
COPY --from=vendor /var/www/html/vendor ./vendor
RUN pnpm install --frozen-lockfile

COPY resources ./resources
COPY public ./public
RUN pnpm run build

# ---------------------------------------------------------------------------
# Runtime: PHP-FPM + Nginx under s6, listening on 8080. The same image serves the web app and, with a
# different command, Horizon and the scheduler (see docker-compose.yml).
# ---------------------------------------------------------------------------
FROM serversideup/php:8.5-fpm-nginx-alpine

ARG USER_ID
ARG GROUP_ID

USER root
WORKDIR /var/www/html

RUN install-php-extensions bcmath gmp intl

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

ENV PHP_OPCACHE_ENABLE=1
ENV SSL_MODE=off
ENV NGINX_FASTCGI_BUFFER_SIZE="8k"
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=true

COPY --chown=www-data:www-data composer.json composer.lock artisan ./
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage

# Autoload and package discovery at build time.
RUN composer dump-autoload --optimize --no-dev && \
    php artisan package:discover --ansi && \
    chown -R www-data:www-data vendor public bootstrap/cache storage

COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

USER www-data
