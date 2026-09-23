FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema
RUN apk add --no-cache \
    nginx \
    mysql-client \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    icu-dev

# Instalar extensiones PHP requeridas por Laravel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        zip \
        gd \
        bcmath \
        mbstring \
        intl \
        opcache

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias PHP
# --no-audit: el bloqueo por advisories de Composer marca TODAS las versiones 11.x de
# laravel/framework (confirmado en Packagist), asi que no hay un patch 11.x libre de avisos
# al que fijar la version; el build vigente en produccion ya corre una version 11.x mas
# antigua sin auditar. Migrar a Laravel 12 esta fuera del alcance de este despliegue.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-security-blocking

# Permisos correctos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
