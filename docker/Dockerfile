FROM composer:2 AS composer

FROM php:8.3-cli AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM runtime AS test

COPY app/composer.json app/composer.lock /app/
RUN composer install --no-interaction --prefer-dist
COPY app/ /app/

CMD ["vendor/bin/phpunit"]

FROM runtime AS production

COPY app/composer.json app/composer.lock /app/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY app/ /app/

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
