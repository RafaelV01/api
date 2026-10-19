<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionExportacion;
use App\Models\CaracterizacionPerfil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Exportaciones largas en segundo plano. El usuario pide la exportación (store), consulta su estado (show) y,
 * cuando está lista, la descarga (descargar). Cada usuario solo ve las suyas.
 */
class ExportacionController extends Controller
{
    /** Máximo de exportaciones sin terminar por usuario, para que nadie sature al generador. */
    private const MAX_EN_CURSO = 3;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tipo' => ['required', 'string', 'in:'.implode(',', CaracterizacionExportacion::TIPOS)],
            'parametros' => 'nullable|array',
        ]);

        $perfil = Auth::user()->caracterizacionPerfil;
        abort_unless($perfil && $perfil->activo, 403, 'No autorizado.');
        // El listado de «Estadísticas por variables» es solo del administrador (igual que su pantalla).
        abort_if($validated['tipo'] === 'variables' && $perfil->rol !== CaracterizacionPerfil::ROL_ADMINISTRADOR, 403, 'No autorizado.');

        $enCurso = CaracterizacionExportacion::where('usuario_id', Auth::id())->whereIn('estado', ['pendiente', 'procesando'])->count();
        if ($enCurso >= self::MAX_EN_CURSO) {
            return response()->json(['message' => 'Ya tienes varias exportaciones en proceso; espera a que terminen.'], 429);
        }

        $exportacion = CaracterizacionExportacion::create([
            'usuario_id' => Auth::id(),
            'tipo' => $validated['tipo'],
            'estado' => 'pendiente',
            // Solo valores simples (texto o listas de texto): son los filtros que ya aceptan las pantallas.
            'parametros' => $this->limpiar($validated['parametros'] ?? []),
        ]);

        return response()->json($this->formato($exportacion), 202);
    }

    public function show(CaracterizacionExportacion $exportacion)
    {
        $this->soloDueno($exportacion);

        return response()->json($this->formato($exportacion));
    }

    public function descargar(CaracterizacionExportacion $exportacion)
    {
        $this->soloDueno($exportacion);
        abort_unless($exportacion->estado === 'listo' && $exportacion->archivo && Storage::disk('local')->exists($exportacion->archivo), 404, 'El archivo ya no está disponible. Genera la exportación de nuevo.');

        return Storage::disk('local')->download($exportacion->archivo, $exportacion->nombre_descarga ?: 'exportacion.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function soloDueno(CaracterizacionExportacion $exportacion): void
    {
        abort_unless((int) $exportacion->usuario_id === (int) Auth::id(), 404);
    }

    private function formato(CaracterizacionExportacion $e): array
    {
        return [
            'id' => $e->id,
            'tipo' => $e->tipo,
            'estado' => $e->estado,
            'bytes' => $e->bytes,
            'error' => $e->estado === 'error' ? 'No se pudo generar el archivo. Intenta de nuevo o reduce los filtros.' : null,
            'segundos' => $e->iniciado_en ? (int) (($e->terminado_en ?? now())->diffInSeconds($e->iniciado_en, true)) : 0,
        ];
    }

    private function limpiar(array $parametros): array
    {
        $limpio = [];
        foreach ($parametros as $clave => $valor) {
            if (! is_string($clave) || strlen($clave) > 60) {
                continue;
            }
            if (is_scalar($valor)) {
                $limpio[$clave] = mb_substr((string) $valor, 0, 300);
            } elseif (is_array($valor)) {
                $limpio[$clave] = array_values(array_map(fn ($v) => mb_substr((string) $v, 0, 300), array_filter($valor, 'is_scalar')));
            }
        }

        return $limpio;
    }
}
