<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Importa el Plan de Desarrollo (sector → programa → meta producto). Lo usan el comando de consola y la pantalla de
 * administración. Es idempotente: la meta se identifica por su número en el plan (`orden`); sin número, por su texto.
 */
class ImportadorPlanDesarrollo
{
    /**
     * @param  array<int, array<string, mixed>>  $filas  [{sector, programa, meta_producto, orden?, vigencia?}]
     * @return array{procesadas: int, nuevas: int, omitidas: int, desactivadas: int}
     */
    public function importar(array $filas, bool $reemplazar = false, string $vigencia = '2024-2027'): array
    {
        $vistas = [];
        $nuevas = 0;
        $omitidas = 0;

        DB::transaction(function () use ($filas, $reemplazar, $vigencia, &$vistas, &$nuevas, &$omitidas, &$desactivadas) {
            foreach ($filas as $f) {
                $sector = trim((string) ($f['sector'] ?? ''));
                $programa = trim((string) ($f['programa'] ?? ''));
                $meta = trim((string) ($f['meta_producto'] ?? ''));
                if ($sector === '' || $programa === '' || $meta === '') {
                    $omitidas++;
                    continue;
                }
                $clave = ['sector' => mb_substr($sector, 0, 150), 'programa' => mb_substr($programa, 0, 255), 'meta_producto' => mb_substr($meta, 0, 800)];
                $orden = (int) ($f['orden'] ?? 0);
                $vig = trim((string) ($f['vigencia'] ?? '')) ?: $vigencia;

                $existe = $orden > 0
                    ? DB::table('caracterizacion_plan_desarrollo')->where('orden', $orden)->where('vigencia', $vig)->first()
                    : DB::table('caracterizacion_plan_desarrollo')->where($clave)->where('vigencia', $vig)->first();

                if ($existe) {
                    DB::table('caracterizacion_plan_desarrollo')->where('id', $existe->id)
                        ->update($clave + ['orden' => $orden, 'activo' => true, 'updated_at' => now()]);
                    $vistas[] = $existe->id;
                } else {
                    $vistas[] = DB::table('caracterizacion_plan_desarrollo')->insertGetId(
                        $clave + ['orden' => $orden, 'vigencia' => $vig, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                    $nuevas++;
                }
            }

            $desactivadas = 0;
            if ($reemplazar && $vistas) {
                $desactivadas = DB::table('caracterizacion_plan_desarrollo')->where('vigencia', $vigencia)->whereNotIn('id', $vistas)->where('activo', true)
                    ->update(['activo' => false]);
            }
        });

        return ['procesadas' => count($vistas), 'nuevas' => $nuevas, 'omitidas' => $omitidas, 'desactivadas' => $desactivadas ?? 0];
    }

    /**
     * Lee un CSV (separado por coma o punto y coma) con encabezados sector, programa, meta_producto y, opcional, orden y vigencia.
     *
     * @return array<int, array<string, string>>
     */
    public function leerCsv(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas = preg_split('/\r\n|\n|\r/', trim($contenido));
        if (count($lineas) < 2) {
            return [];
        }
        $separador = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ',';
        $cabecera = array_map(fn ($c) => mb_strtolower(trim($c)), str_getcsv($lineas[0], $separador));

        $filas = [];
        foreach (array_slice($lineas, 1) as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $valores = str_getcsv($linea, $separador);
            $fila = [];
            foreach ($cabecera as $i => $nombre) {
                $fila[$nombre] = $valores[$i] ?? '';
            }
            $filas[] = $fila;
        }

        return $filas;
    }
}
