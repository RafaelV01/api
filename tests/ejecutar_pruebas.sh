#!/usr/bin/env bash
# Ejecuta TODA la suite de pruebas en un entorno desechable (necesita Docker): levanta una MariaDB temporal, le carga el
# esquema base (init.sql) y las migraciones, siembra los catálogos y corre PHPUnit. No toca ninguna base real.
#
# Uso (desde la carpeta api/):   INIT_SQL=../init.sql bash tests/ejecutar_pruebas.sh [filtro-phpunit]
set -uo pipefail
cd "$(dirname "$0")/.."
INIT_SQL="${INIT_SQL:-../init.sql}"
[ -f "$INIT_SQL" ] || { echo "No encuentro init.sql ($INIT_SQL). Indica la ruta con INIT_SQL=..."; exit 2; }

RED=pruebas-red-$$; BD=pruebas-bd-$$
limpiar() { docker rm -f "$BD" >/dev/null 2>&1; docker network rm "$RED" >/dev/null 2>&1; }
trap limpiar EXIT

echo "→ Construyendo la imagen de la API…"
docker build -q -t asistencia-api:pruebas . >/dev/null || { echo "Falló la construcción de la imagen"; exit 2; }

echo "→ Levantando MariaDB temporal…"
docker network create "$RED" >/dev/null
docker run -d --name "$BD" --network "$RED" -e MARIADB_ROOT_PASSWORD=pruebas -e MARIADB_DATABASE=asistencia_test mariadb:11 >/dev/null
for i in $(seq 1 60); do docker exec "$BD" mariadb -uroot -ppruebas -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
docker exec -i "$BD" mariadb -uroot -ppruebas asistencia_test < "$INIT_SQL"

# init.sql es una foto del esquema inicial: algunas migraciones antiguas ya están aplicadas pero no registradas.
# Se registran como hechas (todo lo anterior a Caracterización) para que solo corran las nuevas.
for m in $(ls database/migrations | sed 's/.php$//' | awk '$0 < "2026_09_22_120000"'); do
  docker exec "$BD" mariadb -uroot -ppruebas asistencia_test -e "INSERT IGNORE INTO migrations (migration, batch) VALUES ('$m', 1)"
done

echo "→ Migraciones, catálogos y pruebas…"
docker run --rm --network "$RED" \
  -e DB_HOST="$BD" -e DB_PASSWORD=pruebas -e DB_DATABASE=asistencia_test -e DB_USERNAME=root -e APP_ENV=testing \
  -e APP_KEY=base64:$(head -c 32 /dev/urandom | base64) \
  asistencia-api:pruebas sh -c "composer install --no-interaction --prefer-dist -q 2>&1 | tail -2; \
    php artisan migrate --force 2>&1 | tail -3; \
    php artisan db:seed --class=CaracterizacionOpcionesSeeder --force 2>&1 | tail -1; \
    php -d memory_limit=1G vendor/bin/phpunit --testdox $*"
