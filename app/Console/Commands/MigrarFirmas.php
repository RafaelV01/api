<?php

namespace App\Console\Commands;

use App\Services\FirmasCiudadano;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Convierte las firmas que están en la base de datos como base64 (`firma_ciudadano_base64`) en archivos.
 *
 * Es seguro y repetible, en dos pasos:
 *   1) sin opciones: crea el archivo de cada firma y lo registra, pero NO toca el base64 (nada se pierde);
 *   2) con --limpiar: vacía el base64 solo de las filas cuyo archivo existe y no está vacío (libera espacio en la base).
 */
class MigrarFirmas extends Command
{
    protected $signature = 'caracterizacion:migrar-firmas {--lote=200 : Filas por tanda} {--limpiar : Vacía el base64 de las filas que ya tienen su archivo}';

    protected $description = 'Pasa las firmas de los ciudadanos de base64 en la base de datos a archivos';

    public function handle(FirmasCiudadano $firmas): int
    {
        $lote = max(10, (int) $this->option('lote'));
        $convertidas = 0;
        $invalidas = 0;

        DB::table('caracterizacion_asistentes')->whereNull('firma_ciudadano_archivo')->whereNotNull('firma_ciudadano_base64')
            ->select('id', 'actividad_id', 'firma_ciudadano_base64')->orderBy('id')
            ->chunkById($lote, function ($filas) use ($firmas, &$convertidas, &$invalidas) {
                foreach ($filas as $fila) {
                    try {
                        $ruta = $firmas->guardar($fila->firma_ciudadano_base64, (int) $fila->actividad_id);
                        DB::table('caracterizacion_asistentes')->where('id', $fila->id)->update(['firma_ciudadano_archivo' => $ruta]);
                        $convertidas++;
                    } catch (\Throwable $e) {
                        // Una firma ilegible o fuera de formato se deja como está (sigue siendo legible por el sistema como base64).
                        $invalidas++;
                        $this->warn("Fila {$fila->id}: ".$e->getMessage());
                    }
                }
                $this->line("… {$convertidas} firmas convertidas");
            });

        $this->info("Firmas convertidas: {$convertidas}. No convertibles (se dejan como estaban): {$invalidas}.");

        if ($this->option('limpiar')) {
            $vaciadas = 0;
            DB::table('caracterizacion_asistentes')->whereNotNull('firma_ciudadano_archivo')->whereNotNull('firma_ciudadano_base64')
                ->select('id', 'firma_ciudadano_archivo')->orderBy('id')
                ->chunkById($lote, function ($filas) use (&$vaciadas) {
                    foreach ($filas as $fila) {
                        if (Storage::disk('local')->exists($fila->firma_ciudadano_archivo) && Storage::disk('local')->size($fila->firma_ciudadano_archivo) > 0) {
                            DB::table('caracterizacion_asistentes')->where('id', $fila->id)->update(['firma_ciudadano_base64' => null]);
                            $vaciadas++;
                        }
                    }
                });
            $this->info("Base64 vaciado en {$vaciadas} filas (su archivo existe y no está vacío).");
        }

        return self::SUCCESS;
    }
}
