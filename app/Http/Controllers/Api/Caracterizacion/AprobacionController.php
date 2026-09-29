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

        // Se recorre y guarda cada actividad como modelo (no un update() de query
        // builder) a propósito: solo así Eloquent dispara 'updating'/'updated' y el
        // trait Auditable deja rastro individual de cada cambio de estado_aprobacion
        // en logs_actividad — la acción más sensible del flujo, que antes quedaba
        // fuera de la auditoría por completo. lockForUpdate() además cierra la
        // ventana de carrera con otra aprobación/rechazo concurrente sobre el mismo
        // conjunto, y el conteo reportado/auditado sale de las filas realmente
        // recorridas, no de un COUNT(*) tomado antes del cambio.
        $afectadas = DB::transaction(function () use ($query, $estadoNuevo, $validated) {
            $actividades = $query->lockForUpdate()->get();

            foreach ($actividades as $actividad) {
                $actividad->update([
                    'estado_aprobacion' => $estadoNuevo,
                    'aprobado_por' => Auth::id(),
                    'fecha_aprobacion' => now(),
                    'observacion_aprobacion' => $validated['observacion'] ?? null,
                ]);
            }

            CaracterizacionAprobacion::create([
                'tipo_objetivo' => $validated['tipo_objetivo'],
                'objetivo_id' => $validated['objetivo_id'],
                'accion' => $validated['accion'],
                'actor_id' => Auth::id(),
                'observacion' => $validated['observacion'] ?? null,
                'actividades_afectadas' => $actividades->count(),
            ]);

            return $actividades->count();
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

    /**
     * Resumen para el panel de Aprobaciones: solo los grupos (secretaría/dependencia/
     * usuario) que realmente tienen actividades pendientes -antes se mostraba el botón
     * de aprobar/rechazar en bloque incluso para grupos vacíos- más un feed de lo último
     * subido, para ver de un vistazo qué se aprobó/rechazó recientemente.
     */
    public function resumen()
    {
        $pendientesPorSecretaria = CaracterizacionActividad::query()
            ->select('secretaria_id', DB::raw('count(*) as pendientes'))
            ->where('estado_aprobacion', 'pendiente')
            ->groupBy('secretaria_id')
            ->with('secretaria:id,nombre')
            ->get()
            ->map(fn ($row) => ['id' => $row->secretaria_id, 'nombre' => $row->secretaria?->nombre, 'pendientes' => $row->pendientes]);

        $pendientesPorDependencia = CaracterizacionActividad::query()
            ->select('dependencia_id', DB::raw('count(*) as pendientes'))
            ->where('estado_aprobacion', 'pendiente')
            ->groupBy('dependencia_id')
            ->with('dependencia:id,nombre,secretaria_id')
            ->get()
            ->map(fn ($row) => ['id' => $row->dependencia_id, 'nombre' => $row->dependencia?->nombre, 'pendientes' => $row->pendientes]);

        $pendientesPorUsuario = CaracterizacionActividad::query()
            ->select('creador_id', DB::raw('count(*) as pendientes'))
            ->where('estado_aprobacion', 'pendiente')
            ->groupBy('creador_id')
            ->with('creador:id,nombre_completo')
            ->get()
            ->map(fn ($row) => ['id' => $row->creador_id, 'nombre' => $row->creador?->nombre_completo, 'pendientes' => $row->pendientes]);

        $recientes = CaracterizacionActividad::query()
            ->with(['creador:id,nombre_completo', 'secretaria:id,nombre', 'dependencia:id,nombre'])
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'tema', 'municipio', 'estado', 'estado_aprobacion', 'creador_id', 'secretaria_id', 'dependencia_id', 'created_at', 'updated_at']);

        return response()->json([
            'por_secretaria' => $pendientesPorSecretaria->values(),
            'por_dependencia' => $pendientesPorDependencia->values(),
            'por_usuario' => $pendientesPorUsuario->values(),
            'recientes' => $recientes,
        ]);
    }
}
