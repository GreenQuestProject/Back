# Dockerfile (Symfony)
FROM php:8.3-fpm

# Dépendances système
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-install intl pdo pdo_mysql zip mbstring gd xml \
    && echo "PHP Extensions installed successfully" \
    || echo "PHP Extensions installation failed"


# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Donne les bons droits
RUN chown -R www-data:www-data /var/www/html/var

EXPOSE 9000

CMD ["php-fpm"]
