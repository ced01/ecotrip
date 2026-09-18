FROM composer:2.9.5 AS composer
FROM php:8.4.19-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libxml2-dev libonig-dev unzip git \
    && docker-php-ext-install pdo_pgsql intl dom xml xmlwriter mbstring \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
