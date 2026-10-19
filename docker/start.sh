#!/bin/sh
# Arranque del contenedor: PHP-FPM (varios procesos) + nginx. Reemplaza a `php artisan serve` (un solo hilo).
set -e
cd /var/www/html
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php-fpm -D

# Generadores de exportaciones en segundo plano (2 procesos; si uno termina o se cae, se vuelve a iniciar solo)
mkdir -p storage/app/private/exportaciones storage/logs
chown -R www-data:www-data storage
for n in 1 2; do
  ( while true; do
      # `|| true`: el script usa `set -e` y un error del generador (p. ej. antes de migrar) no debe matar el ciclo.
      su -s /bin/sh www-data -c "cd /var/www/html && php artisan caracterizacion:procesar-exportaciones" >> storage/logs/exportaciones.log 2>&1 || true
      sleep 3
    done ) &
done

exec nginx -g 'daemon off;'
