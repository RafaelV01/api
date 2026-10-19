#!/bin/sh
# Arranque del contenedor: PHP-FPM (varios procesos) + nginx. Reemplaza a `php artisan serve` (un solo hilo).
set -e
cd /var/www/html
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php-fpm -D
exec nginx -g 'daemon off;'
