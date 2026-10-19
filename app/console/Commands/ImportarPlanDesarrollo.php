<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga el Plan de Desarrollo Departamental (sector → programa → meta producto) desde un JSON:
 *   [{ "sector": "...", "programa": "...", "meta_producto": "...", "orden": 1 }, ...]
 * Es idempotente: reimportar el mismo archivo no duplica filas. Con --reemplazar, las filas que
 * no vengan en el archivo se desactivan (no se borran).
 */
class ImportarPlanDesarrollo extends Command
{
    protected $signature = 'caracterizacion:importar-plan {archivo : Ruta del JSON} {--reemplazar : Desactiva las filas que no estén en el archivo}';

    protected $description = 'Importa el Plan de Desarrollo Departamental (sector, programa, meta producto) desde un JSON';

    public function handle(): int
    {
        $ruta = $this->argument('archivo');
        if (!is_file($ruta)) {
            $this->error("No existe el archivo: {$ruta}");

            return self::FAILURE;
        }

        $filas = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($filas)) {
            $this->error('El archivo no es un JSON válido (se espera un arreglo).');

            return self::FAILURE;
        }

        $vistas = [];
        $nuevas = 0;
        DB::transaction(function () use ($filas, &$vistas, &$nuevas) {
            foreach ($filas as $i => $f) {
                $sector = trim((string) ($f['sector'] ?? ''));
                $programa = trim((string) ($f['programa'] ?? ''));
                $meta = trim((string) ($f['meta_producto'] ?? ''));
                if ($sector === '' || $programa === '' || $meta === '') {
                    $this->warn('Fila '.($i + 1).' omitida (faltan campos).');
                    continue;
                }
                $clave = ['sector' => mb_substr($sector, 0, 150), 'programa' => mb_substr($programa, 0, 255), 'meta_producto' => mb_substr($meta, 0, 800)];
                // El número de meta del plan (orden) identifica la fila; sin él, se compara por texto.
                $orden = (int) ($f['orden'] ?? 0);
                $existe = $orden > 0
                    ? DB::table('caracterizacion_plan_desarrollo')->where('orden', $orden)->first()
                    : DB::table('caracterizacion_plan_desarrollo')->where($clave)->first();
                if ($existe) {
                    DB::table('caracterizacion_plan_desarrollo')->where('id', $existe->id)
                        ->update($clave + ['orden' => $orden, 'activo' => true, 'updated_at' => now()]);
                    $vistas[] = $existe->id;
                } else {
                    $vistas[] = DB::table('caracterizacion_plan_desarrollo')->insertGetId(
                        $clave + ['orden' => (int) ($f['orden'] ?? 0), 'activo' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                    $nuevas++;
                }
            }

            if ($this->option('reemplazar')) {
                DB::table('caracterizacion_plan_desarrollo')->whereNotIn('id', $vistas)->update(['activo' => false]);
            }
        });

        $this->info(count($vistas).' metas procesadas, '.$nuevas.' nuevas.');

        return self::SUCCESS;
    }
}
