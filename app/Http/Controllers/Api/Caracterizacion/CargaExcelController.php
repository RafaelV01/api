<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionCargaExcel;
use App\Services\ImportadorExcelCaracterizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Archivos Excel subidos para estadísticas aparte (solo administradores). Lo que se sube aquí NO se mezcla con las reuniones del sistema:
 * vive en sus propias tablas y solo lo usan las estadísticas de «Archivos Excel».
 */
class CargaExcelController extends Controller
{
    public function index()
    {
        $personas = DB::table('caracterizacion_carga_registros')->selectRaw('carga_id, COUNT(DISTINCT persona_clave) as personas')->groupBy('carga_id')->pluck('personas', 'carga_id');

        return response()->json(
            CaracterizacionCargaExcel::with('usuario:id,nombre_completo')->latest('id')->get()->map(fn ($c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'archivo_original' => $c->archivo_original, 'total_filas' => $c->total_filas,
                'filas_omitidas' => $c->filas_omitidas, 'omitidas_detalle' => $c->omitidas_detalle ?? [], 'personas' => (int) ($personas[$c->id] ?? 0),
                'subido_por' => $c->usuario?->nombre_completo, 'created_at' => $c->created_at,
            ])
        );
    }

    public function store(Request $request, ImportadorExcelCaracterizacion $importador)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'nombre' => 'nullable|string|max:120',
        ], [
            'archivo.required' => 'Elige el archivo de Excel.',
            'archivo.mimes' => 'El archivo debe ser de Excel (.xlsx o .xls) o CSV.',
            'archivo.max' => 'El archivo pesa más de 10 MB.',
        ]);

        // Archivos grandes: más memoria y tiempo solo para esta petición
        @ini_set('memory_limit', '768M');
        @set_time_limit(180);

        $archivo = $request->file('archivo');
        try {
            $leido = $importador->leer($archivo->getRealPath());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['archivo' => [$e->getMessage()]]], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo leer el archivo: parece dañado o no es un Excel válido.', 'errors' => ['archivo' => ['No se pudo leer el archivo.']]], 422);
        }
        if (! $leido['filas']) {
            $msg = 'El archivo no tiene filas de personas con nombre y documento.';

            return response()->json(['message' => $msg, 'errors' => ['archivo' => [$msg]]], 422);
        }

        $nombreOriginal = mb_substr($archivo->getClientOriginalName(), 0, 255);
        $carga = DB::transaction(function () use ($request, $archivo, $leido, $nombreOriginal) {
            $carga = CaracterizacionCargaExcel::create([
                'usuario_id' => Auth::id(),
                'nombre' => $request->filled('nombre') ? $request->input('nombre') : mb_substr(pathinfo($nombreOriginal, PATHINFO_FILENAME), 0, 120),
                'archivo_original' => $nombreOriginal,
                'total_filas' => count($leido['filas']),
                'filas_omitidas' => count($leido['omitidas']),
                'omitidas_detalle' => collect($leido['omitidas'])->take(50)->map(fn ($motivo, $fila) => ['fila' => $fila, 'motivo' => $motivo])->values()->all(),
            ]);
            foreach (array_chunk($leido['filas'], 500) as $lote) {
                DB::table('caracterizacion_carga_registros')->insert(array_map(fn ($f) => $f + ['carga_id' => $carga->id], $lote));
            }
            // Copia del archivo original (trazabilidad); si no se puede guardar, la carga igual vale.
            try {
                $ruta = 'cargas_excel/'.$carga->id.'.'.$archivo->getClientOriginalExtension();
                Storage::disk('local')->put($ruta, file_get_contents($archivo->getRealPath()));
                $carga->update(['archivo_ruta' => $ruta]);
            } catch (\Throwable $e) {
                report($e);
            }

            return $carga;
        });

        return response()->json([
            'message' => 'Archivo cargado: '.count($leido['filas']).' fila(s) leídas'.($leido['omitidas'] ? ', '.count($leido['omitidas']).' omitidas' : '').'.',
            'id' => $carga->id,
            'total_filas' => $carga->total_filas,
            'filas_omitidas' => $carga->filas_omitidas,
        ], 201);
    }

    public function destroy(CaracterizacionCargaExcel $carga)
    {
        DB::transaction(function () use ($carga) {
            if ($carga->archivo_ruta) {
                Storage::disk('local')->delete($carga->archivo_ruta);
            }
            $carga->delete(); // sus registros se borran en cascada
        });

        return response()->json(['message' => 'Archivo eliminado junto con sus datos.']);
    }
}
