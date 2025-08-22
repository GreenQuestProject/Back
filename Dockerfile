# --- stage build: installe les vendors ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- stage runtime: FPM ---
FROM php:8.3-fpm

# Dépendances système nécessaires aux extensions
RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl pdo pdo_mysql zip gd opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copie code + vendors (depuis le stage vendor)
COPY --from=vendor /app /var/www/html

# Cache Symfony prod
ENV APP_ENV=prod
RUN php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod \
 && chown -R www-data:www-data var

# Opcache prod (mini réglages)
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.preload=/var/www/html/config/preload.php"; \
      echo "opcache.preload_user=www-data"; \
      echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Le pool FPM écoute par défaut sur 9000 (TCP). Expose pour Nginx interne.
EXPOSE 9000
CMD ["php-fpm", "-F"]
