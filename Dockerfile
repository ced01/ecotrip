FROM composer:2.9.5 AS composer

FROM php:8.4.19-cli-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libxml2-dev libonig-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql intl dom xml xmlwriter mbstring zip \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --create-home --shell /usr/sbin/nologin app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

FROM php-base AS development
RUN mkdir -p /app/var /home/app/.composer && chown -R app:app /app /home/app
USER app
ENV COMPOSER_HOME=/home/app/.composer
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]

FROM php-base AS production-build
COPY --chown=app:app composer.json composer.lock ./
RUN chown app:app /app
USER app
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --classmap-authoritative
COPY --chown=app:app . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-...cret \
    DB_HOST=database DB_NAME=ecotrip DB_USER=ecotrip DB_PASSWORD=build-only \
    php bin/console importmap:install \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-not-a-runtime-secret \
    DB_HOST=database DB_NAME=ecotrip DB_USER=ecotrip DB_PASSWORD=build-only \
    php bin/console asset-map:compile \
    && rm -rf var/cache/* var/log/*

FROM php-base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=production-build --chown=app:app /app /app
RUN mkdir -p /app/var/cache /app/var/log && chown -R app:app /app/var
USER app
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=6 CMD ["php", "tools/container-healthcheck.php"]
CMD ["php", "-d", "display_errors=0", "-d", "log_errors=1", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
