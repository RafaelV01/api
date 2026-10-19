<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Caracterizacion\ActividadController;
use App\Http\Controllers\Api\Caracterizacion\EstadisticasVariablesController;
use App\Models\CaracterizacionExportacion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Atiende las exportaciones pedidas desde las pantallas: toma la más antigua pendiente, genera el Excel con los
 * mismos controladores y filtros de siempre (a nombre del usuario que la pidió, con su mismo alcance de visibilidad) y
 * la deja lista para descargar. Se ejecuta como proceso permanente junto al servidor (ver docker/start.sh).
 */
class ProcesarExportaciones extends Command
{
    protected $signature = 'caracterizacion:procesar-exportaciones {--una-vez : Procesa lo que haya pendiente y termina}';

    protected $description = 'Genera en segundo plano las exportaciones pendientes (consolidados y listados FO-PDD-19)';

    private const DIAS_ARCHIVO = 2;
    private const SEGUNDOS_MAXIMOS = 3600; // se reinicia cada hora para liberar memoria

    public function handle(): int
    {
        $inicio = time();
        $ultimaLimpieza = 0;
        // Si el proceso se cayó a medias, lo que quedó «procesando» se vuelve a intentar una sola vez.
        CaracterizacionExportacion::where('estado', 'procesando')->where('updated_at', '<', now()->subMinutes(15))->update(['estado' => 'pendiente']);

        do {
            $exportacion = $this->tomarSiguiente();
            if ($exportacion) {
                $this->procesar($exportacion);
                continue;
            }
            if ($this->option('una-vez')) {
                break;
            }
            if (time() - $ultimaLimpieza > 3600) {
                $this->limpiarViejas();
                $ultimaLimpieza = time();
            }
            sleep(2);
        } while (! $this->option('una-vez') || $exportacion);

        return self::SUCCESS;
    }

    /** Reclama la siguiente pendiente de forma atómica (si hay varios procesos, cada uno toma una distinta). */
    private function tomarSiguiente(): ?CaracterizacionExportacion
    {
        foreach (CaracterizacionExportacion::where('estado', 'pendiente')->orderBy('id')->limit(5)->pluck('id') as $id) {
            $tomada = CaracterizacionExportacion::where('id', $id)->where('estado', 'pendiente')
                ->update(['estado' => 'procesando', 'iniciado_en' => now()]);
            if ($tomada) {
                return CaracterizacionExportacion::find($id);
            }
        }

        return null;
    }

    private function procesar(CaracterizacionExportacion $exportacion): void
    {
        try {
            $usuario = User::findOrFail($exportacion->usuario_id);
            Auth::setUser($usuario);
            $peticion = Request::create('/exportacion', 'GET', $exportacion->parametros ?? []);
            $peticion->setUserResolver(fn () => $usuario);

            if ($exportacion->tipo === 'variables') {
                $peticion->query->set('formato', 'fo-pdd-19');
                $respuesta = app(EstadisticasVariablesController::class)->exportar($peticion);
                $nombre = 'estadisticas-variables-FO-PDD-19.xlsx';
            } else {
                $respuesta = app(ActividadController::class)->consolidadoExcel($peticion);
                $nombre = 'consolidado-caracterizacion.xlsx';
            }

            $contenido = $respuesta->getContent();
            if (! is_string($contenido) || ! str_starts_with($contenido, 'PK')) {
                throw new \RuntimeException('La respuesta no es un archivo Excel.');
            }

            $ruta = 'exportaciones/'.$exportacion->id.'.xlsx';
            Storage::disk('local')->put($ruta, $contenido);
            $exportacion->update([
                'estado' => 'listo', 'archivo' => $ruta, 'nombre_descarga' => preg_replace('/\.xlsx$/', '', $nombre).'-'.now()->format('Ymd-His').'.xlsx',
                'bytes' => strlen($contenido), 'terminado_en' => now(), 'error' => null,
            ]);
            $this->info("Exportación {$exportacion->id} lista (".round(strlen($contenido) / 1024).' KB)');
        } catch (\Throwable $e) {
            Log::error('Exportación '.$exportacion->id.' falló: '.$e->getMessage());
            $exportacion->update(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 1000), 'terminado_en' => now()]);
            $this->error("Exportación {$exportacion->id} falló: ".$e->getMessage());
        } finally {
            Auth::forgetGuards();
        }
    }

    private function limpiarViejas(): void
    {
        $viejas = CaracterizacionExportacion::where('created_at', '<', now()->subDays(self::DIAS_ARCHIVO))->whereNotNull('archivo')->get();
        foreach ($viejas as $e) {
            Storage::disk('local')->delete($e->archivo);
            $e->update(['archivo' => null]);
        }
        CaracterizacionExportacion::where('created_at', '<', now()->subDays(14))->delete();
    }
}
