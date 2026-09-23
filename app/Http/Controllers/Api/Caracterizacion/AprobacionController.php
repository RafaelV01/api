<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAprobacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AprobacionController extends Controller
{
    /**
     * Aprueba o rechaza en bloque todas las actividades pendientes que coincidan
     * con el filtro dado (por actividad puntual, usuario, secretaría o dependencia).
     * Deja un registro en caracterizacion_aprobaciones con cuántas actividades afectó.
     */
    public function resolver(Request $request)
    {
        $validated = $request->validate([
            'tipo_objetivo' => ['required', Rule::in(['actividad', 'usuario', 'secretaria', 'dependencia'])],
            'objetivo_id' => 'required|integer',
            'accion' => ['required', Rule::in(['aprobado', 'rechazado'])],
            'observacion' => 'nullable|string|max:500',
        ]);

        $query = CaracterizacionActividad::query()->where('estado_aprobacion', 'pendiente');

        $query = match ($validated['tipo_objetivo']) {
            'actividad' => $query->where('id', $validated['objetivo_id']),
            'usuario' => $query->where('creador_id', $validated['objetivo_id']),
            'secretaria' => $query->where('secretaria_id', $validated['objetivo_id']),
            'dependencia' => $query->where('dependencia_id', $validated['objetivo_id']),
        };

        $estadoNuevo = $validated['accion'] === 'aprobado' ? 'aprobada' : 'rechazada';

        $afectadas = DB::transaction(function () use ($query, $estadoNuevo, $validated) {
            $count = $query->count();

            $query->update([
                'estado_aprobacion' => $estadoNuevo,
                'aprobado_por' => Auth::id(),
                'fecha_aprobacion' => now(),
                'observacion_aprobacion' => $validated['observacion'] ?? null,
            ]);

            CaracterizacionAprobacion::create([
                'tipo_objetivo' => $validated['tipo_objetivo'],
                'objetivo_id' => $validated['objetivo_id'],
                'accion' => $validated['accion'],
                'actor_id' => Auth::id(),
                'observacion' => $validated['observacion'] ?? null,
                'actividades_afectadas' => $count,
            ]);

            return $count;
        });

        return response()->json([
            'message' => "Se {$validated['accion']} {$afectadas} actividad(es).",
            'actividades_afectadas' => $afectadas,
        ]);
    }

    public function historial()
    {
        return response()->json(
            CaracterizacionAprobacion::with('actor:id,nombre_completo')->latest()->paginate(50)
        );
    }
}
