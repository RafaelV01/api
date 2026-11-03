<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionContrato;
use App\Models\CaracterizacionContratoBloque;
use App\Models\CaracterizacionPerfil;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Módulo «Contratistas»: cada quien ve y administra solo los de su alcance (administradores: todos; supervisor: los de su secretaría;
 * referente: los de su dependencia). Desde aquí se editan las credenciales, el contrato y la información de seguimiento del contrato,
 * que se copia automáticamente a las reuniones del contratista. Solo el supervisor, el referente y los administradores pueden cambiarla.
 */
class ContratistaController extends Controller
{
    public function index(Request $request)
    {
        $actor = $this->actor();
        $query = $this->ambito(CaracterizacionPerfil::where('rol', CaracterizacionPerfil::ROL_CONTRATISTA), $actor)
            ->with(['usuario:id,nombre_completo,email,telefono,cargo', 'secretaria:id,nombre', 'dependencia:id,nombre']);

        if ($request->filled('secretaria_id')) {
            $query->where('secretaria_id', $request->integer('secretaria_id'));
        }
        if ($request->filled('dependencia_id')) {
            $query->where('dependencia_id', $request->integer('dependencia_id'));
        }
        if ($request->filled('q')) {
            $texto = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('q')).'%';
            $query->where(fn ($w) => $w->whereHas('usuario', fn ($u) => $u->where('nombre_completo', 'like', $texto)->orWhere('email', 'like', $texto))
                ->orWhereIn('usuario_id', CaracterizacionContrato::where('numero_contrato', 'like', $texto)->select('usuario_id')));
        }

        $pagina = $query->orderBy('secretaria_id')->orderBy('dependencia_id')->orderBy('id')->paginate(min(max($request->integer('per_page', 25), 5), 100));
        $ids = collect($pagina->items())->pluck('usuario_id');

        $contratos = CaracterizacionContrato::whereIn('usuario_id', $ids)->orderBy('fecha_inicio')->orderBy('id')->get()->groupBy('usuario_id')->map->last();
        $bloques = CaracterizacionContratoBloque::whereIn('contrato_id', $contratos->pluck('id'))->selectRaw('contrato_id, COUNT(*) as n')->groupBy('contrato_id')->pluck('n', 'contrato_id');
        $actividades = CaracterizacionActividad::whereIn('creador_id', $ids)->selectRaw('creador_id, estado_aprobacion, COUNT(*) as n')->groupBy('creador_id', 'estado_aprobacion')->get()->groupBy('creador_id');

        $pagina->setCollection($pagina->getCollection()->map(function ($perfil) use ($contratos, $bloques, $actividades) {
            $c = $contratos->get($perfil->usuario_id);

            return [
                'id' => $perfil->usuario_id, 'nombre_completo' => $perfil->usuario?->nombre_completo, 'email' => $perfil->usuario?->email,
                'telefono' => $perfil->usuario?->telefono, 'cargo' => $perfil->usuario?->cargo, 'activo' => (bool) $perfil->activo,
                'secretaria' => $perfil->secretaria?->nombre, 'dependencia' => $perfil->dependencia?->nombre,
                'contrato' => $c ? ['numero' => $c->numero_contrato, 'inicio' => $c->fecha_inicio->toDateString(), 'fin' => $c->fecha_fin->toDateString(), 'estado' => $c->estado(), 'bloques' => (int) ($bloques[$c->id] ?? 0)] : null,
                'reuniones' => ($actividades->get($perfil->usuario_id) ?? collect())->pluck('n', 'estado_aprobacion'),
            ];
        }));

        return response()->json($pagina);
    }

    public function show($id)
    {
        $perfil = $this->perfilDeContratista($id);
        $usuario = $perfil->usuario;
        $contrato = CaracterizacionContrato::actualDe($usuario->id);

        return response()->json([
            'id' => $usuario->id, 'nombre_completo' => $usuario->nombre_completo, 'email' => $usuario->email, 'telefono' => $usuario->telefono,
            'cargo' => $usuario->cargo, 'activo' => (bool) $perfil->activo, 'secretaria_id' => $perfil->secretaria_id, 'dependencia_id' => $perfil->dependencia_id,
            'contrato' => $contrato ? [
                'numero' => $contrato->numero_contrato, 'inicio' => $contrato->fecha_inicio->toDateString(), 'fin' => $contrato->fecha_fin->toDateString(), 'estado' => $contrato->estado(),
                'bloques' => $contrato->bloques->map(fn ($b) => $this->bloqueAArray($b))->values(),
            ] : null,
            'contratos_anteriores' => CaracterizacionContrato::where('usuario_id', $usuario->id)->when($contrato, fn ($q) => $q->where('id', '!=', $contrato->id))
                ->orderByDesc('fecha_inicio')->get(['numero_contrato', 'fecha_inicio', 'fecha_fin'])->map(fn ($c) => ['numero' => $c->numero_contrato, 'inicio' => $c->fecha_inicio->toDateString(), 'fin' => $c->fecha_fin->toDateString()]),
        ]);
    }

    /** Credenciales y datos personales: nombre, correo, teléfono, cargo, contraseña nueva y habilitar/deshabilitar. */
    public function actualizar(Request $request, $id)
    {
        $perfil = $this->perfilDeContratista($id);
        $usuario = $perfil->usuario;

        $validated = $request->validate([
            'nombre_completo' => 'sometimes|string|min:3|max:160',
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9 +()\-]{7,30}$/'],
            'cargo' => 'sometimes|nullable|string|max:120',
            'password' => 'sometimes|nullable|string|min:8|max:100',
            'activo' => 'sometimes|boolean',
        ], ['telefono.regex' => 'El teléfono solo puede tener números, espacios y + - ( ).']);

        DB::transaction(function () use ($validated, $usuario, $perfil) {
            $usuario->fill(collect($validated)->only(['nombre_completo', 'email', 'telefono', 'cargo'])->all());
            if (! empty($validated['password'])) {
                $usuario->password = Hash::make($validated['password']);
            }
            $usuario->save();
            if (! empty($validated['password']) || (array_key_exists('activo', $validated) && ! $validated['activo'])) {
                $usuario->tokens()->delete(); // cambió la contraseña o lo deshabilitaron: se cierran sus sesiones abiertas
            }
            if (array_key_exists('activo', $validated)) {
                $perfil->update(['activo' => (bool) $validated['activo']]);
            }
        });

        return response()->json(['message' => 'Datos del contratista actualizados.']);
    }

    /**
     * Contrato y bloques de seguimiento. Con el mismo número de contrato se actualiza el actual; con un número nuevo se crea otro contrato y
     * el anterior queda en el historial. Las reuniones ya creadas NO cambian: tienen su propia copia de lo que decía el contrato cuando se crearon.
     */
    public function guardarContrato(Request $request, $id)
    {
        $perfil = $this->perfilDeContratista($id);
        $usuario = $perfil->usuario;

        $validated = $request->validate([
            'numero_contrato' => ['required', 'string', 'max:60', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} .\-\/]*$/u'],
            'fecha_inicio' => 'required|date_format:Y-m-d',
            'fecha_fin' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'bloques' => 'required|array|min:1|max:'.CaracterizacionContratoBloque::MAX_POR_CONTRATO,
            ...CaracterizacionContratoBloque::reglas('bloques.*.'),
        ], [
            'numero_contrato.regex' => 'El número de contrato solo puede tener letras, números, espacios y . - /',
            'fecha_fin.after_or_equal' => 'La fecha final del contrato no puede ser anterior a la de inicio.',
            'bloques.max' => 'Un contrato puede tener hasta '.CaracterizacionContratoBloque::MAX_POR_CONTRATO.' actividades.',
            'bloques.min' => 'Agrega al menos una actividad al contrato.',
        ]);

        $contrato = DB::transaction(function () use ($validated, $usuario) {
            $actual = CaracterizacionContrato::actualDe($usuario->id);
            $datos = ['numero_contrato' => $validated['numero_contrato'], 'fecha_inicio' => $validated['fecha_inicio'], 'fecha_fin' => $validated['fecha_fin'], 'activo' => true];
            if ($actual && $actual->numero_contrato === $validated['numero_contrato']) {
                $actual->update($datos);
                $contrato = $actual;
            } else {
                $contrato = CaracterizacionContrato::create(['usuario_id' => $usuario->id] + $datos);
            }

            foreach (array_values($validated['bloques']) as $i => $b) {
                $campos = ['nombre' => $b['nombre'] ?? 'Actividad '.($i + 1)];
                foreach (CaracterizacionContratoBloque::CAMPOS as $campo) {
                    $campos[$campo] = ($b[$campo] ?? null) === '' ? null : ($b[$campo] ?? null);
                }
                $existente = CaracterizacionContratoBloque::where('contrato_id', $contrato->id)->where('orden', $i + 1)->first();
                $existente ? $existente->update($campos) : CaracterizacionContratoBloque::create(['contrato_id' => $contrato->id, 'orden' => $i + 1] + $campos);
            }
            // Los bloques que sobran (se quitó una actividad) se borran uno a uno para que quede rastro de auditoría
            CaracterizacionContratoBloque::where('contrato_id', $contrato->id)->where('orden', '>', count($validated['bloques']))->get()->each->delete();

            return $contrato;
        });

        return response()->json(['message' => 'Contrato guardado. Las reuniones nuevas del contratista usarán esta información.', 'contrato_id' => $contrato->id]);
    }

    /** Contrato del contratista que consulta (lo usa al crear una reunión para elegir su actividad). */
    public function miContrato()
    {
        $contrato = CaracterizacionContrato::actualDe(Auth::id());

        return response()->json([
            'contrato' => $contrato ? ['numero' => $contrato->numero_contrato, 'inicio' => $contrato->fecha_inicio->toDateString(), 'fin' => $contrato->fecha_fin->toDateString(), 'estado' => $contrato->estado()] : null,
            'bloques' => $contrato ? $contrato->bloques->map(fn ($b) => ['id' => $b->id, 'orden' => $b->orden, 'nombre' => $b->nombre, 'programa' => $b->item26_programa, 'meta' => $b->item26_meta_producto, 'bien_servicio' => $b->item21_bien_servicio, 'proyecto' => $b->item27_nombre_proyecto])->values() : [],
        ]);
    }

    private function bloqueAArray($b): array
    {
        $a = ['id' => $b->id, 'orden' => $b->orden, 'nombre' => $b->nombre];
        foreach (CaracterizacionContratoBloque::CAMPOS as $campo) {
            $valor = $b->{$campo};
            $a[$campo] = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor;
        }

        return $a;
    }

    private function actor(): CaracterizacionPerfil
    {
        $perfil = Auth::user()->caracterizacionPerfil;
        abort_unless($perfil && $perfil->activo && $perfil->rol !== CaracterizacionPerfil::ROL_CONTRATISTA, 403, 'No autorizado.');

        return $perfil;
    }

    /** Limita una consulta de perfiles al alcance de quien consulta. */
    private function ambito($query, CaracterizacionPerfil $actor)
    {
        return match (true) {
            $actor->esGlobal() => $query,
            $actor->rol === CaracterizacionPerfil::ROL_SUPERVISOR => $query->where('secretaria_id', $actor->secretaria_id ?: 0),
            $actor->rol === CaracterizacionPerfil::ROL_REFERENTE => $query->where('dependencia_id', $actor->dependencia_id ?: 0),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Perfil del contratista pedido, solo si está dentro del alcance de quien consulta. */
    private function perfilDeContratista($id): CaracterizacionPerfil
    {
        $perfil = $this->ambito(CaracterizacionPerfil::where('rol', CaracterizacionPerfil::ROL_CONTRATISTA)->where('usuario_id', $id), $this->actor())->with('usuario')->first();
        abort_unless($perfil && $perfil->usuario, 404, 'No encontramos a ese contratista en tu alcance.');

        return $perfil;
    }
}
