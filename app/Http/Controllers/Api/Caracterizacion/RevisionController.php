<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAprobacion;
use App\Models\CaracterizacionNotificacion;
use App\Models\CaracterizacionPerfil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Revisión en cadena de las reuniones: el contratista la termina y la ENVÍA → la revisa el REFERENTE de su dependencia (aprueba o la
 * devuelve diciendo qué falta) → la revisa el SUPERVISOR de la secretaría (último filtro) → queda APROBADA y llega al administrador.
 * Estados (estado_aprobacion): borrador → pendiente (con el referente) → revision_supervisor → aprobada; «rechazada» = devuelta.
 */
class RevisionController extends Controller
{
    /** El contratista (o un administrador) envía la reunión a revisión. */
    public function enviar(CaracterizacionActividad $actividad)
    {
        $perfil = $this->perfil();
        abort_unless($actividad->creador_id === Auth::id() || $perfil->esGlobal(), 403, 'Solo quien creó la reunión puede enviarla a revisión.');

        $resultado = $this->enviarUna($actividad);
        if ($resultado !== true) {
            return response()->json(['message' => $resultado], 422);
        }

        return response()->json($actividad->fresh(['dependencia', 'secretaria']));
    }

    /** Envía de una vez todos los borradores (y devueltas) del contratista que ya tienen asistentes. */
    public function enviarBorradores()
    {
        $this->perfil();
        $enviadas = 0;
        $omitidas = 0;
        CaracterizacionActividad::where('creador_id', Auth::id())->whereIn('estado_aprobacion', [CaracterizacionActividad::BORRADOR, CaracterizacionActividad::RECHAZADA])
            ->get()->each(function ($a) use (&$enviadas, &$omitidas) {
                $this->enviarUna($a) === true ? $enviadas++ : $omitidas++;
            });

        return response()->json([
            'enviadas' => $enviadas, 'sin_asistentes' => $omitidas,
            'message' => $enviadas ? "Se enviaron {$enviadas} reunión(es) a revisión.".($omitidas ? " {$omitidas} no se enviaron porque aún no tienen asistentes." : '') : 'No hay reuniones listas para enviar (deben tener al menos un asistente).',
        ]);
    }

    /** @return true|string  true si se envió; si no, el motivo */
    private function enviarUna(CaracterizacionActividad $actividad)
    {
        if (! in_array($actividad->estado_aprobacion, [CaracterizacionActividad::BORRADOR, CaracterizacionActividad::RECHAZADA], true)) {
            return 'Esta reunión ya fue enviada a revisión.';
        }
        if (! $actividad->asistentes()->exists()) {
            return 'La reunión todavía no tiene asistentes registrados: no hay nada que revisar.';
        }

        // Si la dependencia no tiene referente activo, la revisión pasa directo al supervisor.
        $hayReferente = $this->usuariosDe(CaracterizacionPerfil::ROL_REFERENTE, null, $actividad->dependencia_id)->isNotEmpty();
        $actividad->update([
            'estado_aprobacion' => $hayReferente ? CaracterizacionActividad::PENDIENTE : CaracterizacionActividad::REVISION_SUPERVISOR,
            'enviada_en' => now(), 'observacion_aprobacion' => null, 'referente_aprobo_por' => null, 'referente_aprobo_en' => null,
            'aprobado_por' => null, 'fecha_aprobacion' => null,
        ]);

        $destinatarios = $hayReferente
            ? $this->usuariosDe(CaracterizacionPerfil::ROL_REFERENTE, null, $actividad->dependencia_id)
            : $this->usuariosDe(CaracterizacionPerfil::ROL_SUPERVISOR, $actividad->secretaria_id, null);
        foreach ($destinatarios as $usuarioId) {
            CaracterizacionNotificacion::enviar($usuarioId, 'revision', 'Una reunión espera tu revisión', '«'.$actividad->tema.'» — '.(Auth::user()->nombre_completo ?? 'un contratista'), '/caracterizacion/revision');
        }

        return true;
    }

    /** Bandeja de revisión de quien consulta: lo que le toca revisar según su rol. */
    public function bandeja()
    {
        $perfil = $this->perfil();
        $query = CaracterizacionActividad::query()->with(['creador:id,nombre_completo', 'dependencia:id,nombre', 'secretaria:id,nombre'])->withCount('asistentes');

        match (true) {
            $perfil->esGlobal() => $query->whereIn('estado_aprobacion', [CaracterizacionActividad::PENDIENTE, CaracterizacionActividad::REVISION_SUPERVISOR]),
            $perfil->rol === CaracterizacionPerfil::ROL_REFERENTE => $query->where('estado_aprobacion', CaracterizacionActividad::PENDIENTE)
                ->where('dependencia_id', $perfil->dependencia_id ?: 0),
            $perfil->rol === CaracterizacionPerfil::ROL_SUPERVISOR => $query->where('estado_aprobacion', CaracterizacionActividad::REVISION_SUPERVISOR)
                ->where('secretaria_id', $perfil->secretaria_id ?: 0),
            default => abort(403, 'Tu rol no revisa reuniones.'),
        };

        $lista = $query->orderBy('enviada_en')->orderBy('id')->limit(300)
            ->get(['id', 'codigo', 'tema', 'fecha', 'municipio', 'creador_id', 'dependencia_id', 'secretaria_id', 'estado_aprobacion', 'enviada_en', 'referente_aprobo_en', 'observacion_aprobacion']);

        return response()->json(['total' => $lista->count(), 'data' => $lista]);
    }

    /** Aprueba (pasa a la siguiente etapa) o devuelve la reunión al contratista con lo que le falta. */
    public function resolver(Request $request, CaracterizacionActividad $actividad)
    {
        $perfil = $this->perfil();
        $validated = $request->validate([
            'accion' => 'required|in:aprobar,devolver',
            'observacion' => 'required_if:accion,devolver|nullable|string|min:5|max:500',
        ], ['observacion.required_if' => 'Escribe qué le falta o qué debe corregir el contratista.', 'observacion.min' => 'Explica con un poco más de detalle lo que falta.']);

        $estado = $actividad->estado_aprobacion;
        $etapaReferente = $estado === CaracterizacionActividad::PENDIENTE;
        $etapaSupervisor = $estado === CaracterizacionActividad::REVISION_SUPERVISOR;
        $puede = match (true) {
            $perfil->esGlobal() => $etapaReferente || $etapaSupervisor,
            $perfil->rol === CaracterizacionPerfil::ROL_REFERENTE => $etapaReferente && $perfil->dependencia_id && $perfil->dependencia_id === $actividad->dependencia_id,
            $perfil->rol === CaracterizacionPerfil::ROL_SUPERVISOR => $etapaSupervisor && $perfil->secretaria_id && $perfil->secretaria_id === $actividad->secretaria_id,
            default => false,
        };
        abort_unless($puede, 403, 'No te corresponde revisar esta reunión en su estado actual.');

        DB::transaction(function () use ($actividad, $validated, $etapaReferente, $perfil) {
            if ($validated['accion'] === 'devolver') {
                $actividad->update([
                    'estado_aprobacion' => CaracterizacionActividad::RECHAZADA, 'observacion_aprobacion' => $validated['observacion'],
                    'aprobado_por' => Auth::id(), 'fecha_aprobacion' => now(),
                ]);
            } elseif ($etapaReferente && ! $perfil->esGlobal()) {
                $actividad->update([
                    'estado_aprobacion' => CaracterizacionActividad::REVISION_SUPERVISOR, 'referente_aprobo_por' => Auth::id(), 'referente_aprobo_en' => now(),
                ]);
            } else {
                $actividad->update([
                    'estado_aprobacion' => CaracterizacionActividad::APROBADA, 'aprobado_por' => Auth::id(), 'fecha_aprobacion' => now(), 'observacion_aprobacion' => null,
                ]);
            }
            CaracterizacionAprobacion::create([
                'tipo_objetivo' => 'actividad', 'objetivo_id' => $actividad->id, 'actor_id' => Auth::id(), 'actividades_afectadas' => 1,
                'accion' => $validated['accion'] === 'devolver' ? 'rechazado' : 'aprobado', 'observacion' => $validated['observacion'] ?? null,
            ]);
        });

        $actividad->refresh();
        $tema = '«'.$actividad->tema.'»';
        if ($actividad->estado_aprobacion === CaracterizacionActividad::RECHAZADA) {
            CaracterizacionNotificacion::enviar((int) $actividad->creador_id, 'rechazada', 'Te devolvieron una reunión para corregir', $tema.' — '.$actividad->observacion_aprobacion, '/caracterizacion/actividades/'.$actividad->id);
        } elseif ($actividad->estado_aprobacion === CaracterizacionActividad::REVISION_SUPERVISOR) {
            foreach ($this->usuariosDe(CaracterizacionPerfil::ROL_SUPERVISOR, $actividad->secretaria_id, null) as $usuarioId) {
                CaracterizacionNotificacion::enviar($usuarioId, 'revision', 'Una reunión espera tu revisión', $tema.' ya fue aprobada por el referente.', '/caracterizacion/revision');
            }
        } else {
            CaracterizacionNotificacion::enviar((int) $actividad->creador_id, 'aprobada', 'Tu reunión fue aprobada', $tema, '/caracterizacion/actividades/'.$actividad->id);
        }

        return response()->json($actividad);
    }

    private function perfil(): CaracterizacionPerfil
    {
        $perfil = Auth::user()->caracterizacionPerfil;
        abort_unless($perfil && $perfil->activo, 403, 'No autorizado.');

        return $perfil;
    }

    /** Ids de los usuarios activos con ese rol en la secretaría o dependencia. */
    private function usuariosDe(string $rol, ?int $secretariaId, ?int $dependenciaId)
    {
        return CaracterizacionPerfil::where('rol', $rol)->where('activo', true)
            ->when($secretariaId, fn ($q) => $q->where('secretaria_id', $secretariaId))
            ->when($dependenciaId, fn ($q) => $q->where('dependencia_id', $dependenciaId))
            ->pluck('usuario_id');
    }
}
