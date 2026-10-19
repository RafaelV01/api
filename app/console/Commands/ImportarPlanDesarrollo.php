<?php

namespace App\Console\Commands;

use App\Services\ImportadorPlanDesarrollo;
use Illuminate\Console\Command;

/**
 * Carga el Plan de Desarrollo Departamental (sector → programa → meta producto) desde un JSON:
 *   [{ "sector": "...", "programa": "...", "meta_producto": "...", "orden": 1 }, ...]
 * Es idempotente: reimportar el mismo archivo no duplica filas. Con --reemplazar, las filas de esa vigencia que no
 * vengan en el archivo se desactivan (no se borran). La misma carga se puede hacer desde la pantalla de administración.
 */
class ImportarPlanDesarrollo extends Command
{
    protected $signature = 'caracterizacion:importar-plan {archivo : Ruta del JSON} {--reemplazar : Desactiva las filas de la vigencia que no estén en el archivo} {--vigencia=2024-2027 : Vigencia del plan}';

    protected $description = 'Importa el Plan de Desarrollo Departamental (sector, programa, meta producto) desde un JSON';

    public function handle(ImportadorPlanDesarrollo $importador): int
    {
        $ruta = $this->argument('archivo');
        if (! is_file($ruta)) {
            $this->error("No existe el archivo: {$ruta}");

            return self::FAILURE;
        }

        $filas = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($filas)) {
            $this->error('El archivo no es un JSON válido (se espera un arreglo).');

            return self::FAILURE;
        }

        $r = $importador->importar($filas, (bool) $this->option('reemplazar'), (string) $this->option('vigencia'));
        $this->info("{$r['procesadas']} metas procesadas, {$r['nuevas']} nuevas, {$r['omitidas']} omitidas, {$r['desactivadas']} desactivadas.");

        return self::SUCCESS;
    }
}
