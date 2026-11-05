FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer
FROM php:8.4.23-fpm-bookworm@sha256:66a10c4b32b2f5886c32a4fb23c6cc3e727661e6a929c241befb335aaee0290e
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libonig-dev libxml2-dev unzip && docker-php-ext-install pdo_pgsql zip mbstring xml pcntl opcache && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY backend/ ./
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && composer dump-autoload --no-dev --classmap-authoritative --no-scripts
RUN printf 'opcache.enable=1\nopcache.validate_timestamps=0\nexpose_php=Off\ndisplay_errors=Off\n' > /usr/local/etc/php/conf.d/flowmv.ini
RUN rm -rf tests .ai .phpunit.cache && rm -f /usr/bin/composer
USER www-data
CMD ["php-fpm", "-F"]
