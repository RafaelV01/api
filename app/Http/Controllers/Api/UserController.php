<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionPerfil;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // ─── Perfil propio: ver ───────────────────────────────────────────────────
    public function perfil(Request $request)
    {
        return response()->json($this->formatUser($request->user()->load('role')));
    }

    // ─── Perfil propio: actualizar ────────────────────────────────────────────
    public function actualizarPerfil(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'nombre_completo' => 'sometimes|string|max:160',
            'cargo'           => 'sometimes|string|max:120',
            'dependencia'     => 'sometimes|string|max:160',
            'telefono'        => 'sometimes|string|max:30',
            'email'           => ['sometimes', 'email', 'max:190', Rule::unique('usuarios')->ignore($user->id)],
        ]);

        $user->fill($request->only(['nombre_completo', 'cargo', 'dependencia', 'telefono', 'email']));
        $user->save();

        return response()->json([
            'message' => 'Perfil actualizado correctamente.',
            'user'    => $this->formatUser($user->load('role')),
        ]);
    }

    // ─── ADMIN: listar todos los usuarios ────────────────────────────────────
    public function index(Request $request)
    {
        $consulta = User::with(['role', 'caracterizacionPerfil'])->orderBy('nombre_completo');

        // Buscador en el servidor: nombre, correo, cargo o dependencia
        if ($request->filled('q')) {
            $texto = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('q'))).'%';
            $consulta->where(fn ($w) => $w->where('nombre_completo', 'like', $texto)->orWhere('email', 'like', $texto)
                ->orWhere('cargo', 'like', $texto)->orWhere('dependencia', 'like', $texto));
        }

        // Con `page` o `per_page` responde paginado; sin ellos, la lista completa (clientes antiguos).
        if ($request->filled('page') || $request->filled('per_page')) {
            $pagina = $consulta->paginate(min(max($request->integer('per_page', 20), 5), 100));
            $pagina->getCollection()->transform(fn ($u) => $this->formatUser($u));

            return response()->json($pagina);
        }

        return response()->json($consulta->get()->map(fn ($u) => $this->formatUser($u)));
    }

    // ─── ADMIN: crear usuario ─────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'nombre_completo' => 'required|string|max:160',
            'cargo'           => 'required|string|max:120',
            'dependencia'     => 'required|string|max:160',
            'email'           => 'required|email|max:190|unique:usuarios',
            'telefono'        => 'required|string|max:30',
            'password'        => 'required|string|min:8|confirmed',
            'rol_id'          => 'required|integer|exists:roles,id',
        ]);

        $user = new User([
            'nombre_completo' => $request->nombre_completo,
            'cargo'           => $request->cargo,
            'dependencia'     => $this->textoDependencia($request) ?? $request->dependencia,
            'email'           => $request->email,
            'telefono'        => $request->telefono,
            'password'        => Hash::make($request->password),
        ]);
        $user->rol_id = $request->rol_id;
        $user->save();

        if ($request->has('caracterizacion_rol')) {
            $this->guardarPerfilCaracterizacion($request, $user);
        }

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'user'    => $this->formatUser($user->load(['role', 'caracterizacionPerfil'])),
        ], 201);
    }

    // ─── ADMIN: ver un usuario ────────────────────────────────────────────────
    public function show(Request $request, $id)
    {
        $user = User::with(['role', 'caracterizacionPerfil'])->findOrFail($id);
        return response()->json($this->formatUser($user));
    }

    // ─── ADMIN: editar cualquier usuario / Usuario: editar su propio perfil ──
    public function update(Request $request, $id)
    {
        $authUser = $request->user();
        $user     = User::findOrFail($id);

        // Un usuario normal solo puede editar su propio perfil
        if ($authUser->rol_id !== 1 && $authUser->id !== $user->id) {
            return response()->json(['message' => 'No tienes permisos para editar este usuario.'], 403);
        }

        $rules = [
            'nombre_completo' => 'sometimes|string|max:160',
            'cargo'           => 'sometimes|string|max:120',
            'dependencia'     => 'sometimes|string|max:160',
            'telefono'        => 'sometimes|string|max:30',
            'email'           => ['sometimes', 'email', 'max:190', Rule::unique('usuarios')->ignore($user->id)],
        ];

        $request->validate($rules);

        $user->fill($request->only(['nombre_completo', 'cargo', 'dependencia', 'telefono', 'email']));
        if ($authUser->rol_id === 1 && ($texto = $this->textoDependencia($request))) {
            $user->dependencia = $texto;
        }

        // Solo el admin puede cambiar el rol, asignado directamente para evitar mass assignment
        if ($authUser->rol_id === 1 && $request->has('rol_id')) {
            $validated_rol = validator(['rol_id' => $request->rol_id], ['rol_id' => 'integer|exists:roles,id'])->validate();
            $user->rol_id = $validated_rol['rol_id'];
        }

        $user->save();

        // Solo el admin puede asignar el rol de Caracterización de Ciudadanía
        // (independiente de rol_id/roles, que es del sistema de Reuniones).
        if ($authUser->rol_id === 1 && $request->has('caracterizacion_rol')) {
            $this->guardarPerfilCaracterizacion($request, $user);
        }

        $user->load(['role', 'caracterizacionPerfil']);

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'user'    => $this->formatUser($user),
        ]);
    }

    /**
     * El campo «dependencia» (texto) del sistema de Reuniones ya no se escribe a mano: se arma con la
     * secretaría y la dependencia elegidas en los desplegables («Secretaría — Dependencia»), para que
     * toda la gente quede asociada a una dependencia real. Null si no se envió ninguna.
     */
    private function textoDependencia(Request $request): ?string
    {
        $ids = $request->validate([
            'caracterizacion_secretaria_id' => 'nullable|integer|exists:caracterizacion_secretarias,id',
            'caracterizacion_dependencia_id' => 'nullable|integer|exists:caracterizacion_dependencias,id',
        ]);

        $dependencia = !empty($ids['caracterizacion_dependencia_id'])
            ? \App\Models\CaracterizacionDependencia::with('secretaria')->find($ids['caracterizacion_dependencia_id'])
            : null;
        $secretaria = $dependencia?->secretaria
            ?? (!empty($ids['caracterizacion_secretaria_id']) ? \App\Models\CaracterizacionSecretaria::find($ids['caracterizacion_secretaria_id']) : null);

        if (!$secretaria) {
            return null;
        }

        return mb_substr($dependencia ? $secretaria->nombre.' — '.$dependencia->nombre : $secretaria->nombre, 0, 160);
    }

    // ─── ADMIN: asigna/actualiza el perfil de Caracterización de un usuario ───
    private function guardarPerfilCaracterizacion(Request $request, User $user): void
    {
        $validated = $request->validate([
            'caracterizacion_rol' => ['nullable', Rule::in(CaracterizacionPerfil::ROLES)],
            // El supervisor se asigna a una secretaría; el referente y el contratista, además, a una dependencia.
            'caracterizacion_secretaria_id' => 'nullable|integer|exists:caracterizacion_secretarias,id|required_if:caracterizacion_rol,'.CaracterizacionPerfil::ROL_SUPERVISOR.','.CaracterizacionPerfil::ROL_REFERENTE.','.CaracterizacionPerfil::ROL_CONTRATISTA,
            'caracterizacion_dependencia_id' => 'nullable|integer|exists:caracterizacion_dependencias,id|required_if:caracterizacion_rol,'.CaracterizacionPerfil::ROL_REFERENTE.','.CaracterizacionPerfil::ROL_CONTRATISTA,
            // Contrato del contratista (al terminar, se deshabilita su acceso). Los tres datos van juntos.
            'contrato_numero' => ['nullable', 'string', 'max:60', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} .\-\/]*$/u', 'required_with:contrato_inicio,contrato_fin'],
            'contrato_inicio' => 'nullable|date_format:Y-m-d|required_with:contrato_numero,contrato_fin',
            'contrato_fin' => 'nullable|date_format:Y-m-d|after_or_equal:contrato_inicio|required_with:contrato_numero,contrato_inicio',
        ], [
            'contrato_fin.after_or_equal' => 'La fecha final del contrato no puede ser anterior a la de inicio.',
            'contrato_numero.regex' => 'El número de contrato solo puede tener letras, números, espacios y . - /',
        ]);

        // Los roles globales (super admin / administrador) solo los asigna o quita un super admin; mientras no exista ninguno,
        // los asigna el administrador del sistema (quien parte la configuración).
        $rolAntes = $user->caracterizacionPerfil?->rol;
        $rolNuevo = $validated['caracterizacion_rol'] ?? null;
        if ($rolAntes !== $rolNuevo && (in_array($rolNuevo, CaracterizacionPerfil::ROLES_GLOBALES, true) || in_array($rolAntes, CaracterizacionPerfil::ROLES_GLOBALES, true))) {
            $esSuper = $request->user()->caracterizacionPerfil?->rol === CaracterizacionPerfil::ROL_SUPER_ADMIN;
            abort_unless($esSuper || ! CaracterizacionPerfil::where('rol', CaracterizacionPerfil::ROL_SUPER_ADMIN)->exists(), 403, 'Solo un super administrador puede asignar o quitar el rol de administrador.');
        }

        if (!$validated['caracterizacion_rol']) {
            // ->delete() sobre la relación (query builder) no dispara el evento
            // Eloquent 'deleted' — hay que borrar la instancia del modelo para que
            // Auditable registre quién revocó el perfil y con qué datos previos.
            $user->caracterizacionPerfil?->delete();
            return;
        }

        CaracterizacionPerfil::updateOrCreate(
            ['usuario_id' => $user->id],
            [
                'rol' => $validated['caracterizacion_rol'],
                'secretaria_id' => $validated['caracterizacion_secretaria_id'] ?? null,
                'dependencia_id' => $validated['caracterizacion_dependencia_id'] ?? null,
                'activo' => true,
            ]
        );

        // El contrato vigente (el de fecha final más reciente) se actualiza; si no hay ninguno, se crea.
        if ($validated['caracterizacion_rol'] === CaracterizacionPerfil::ROL_CONTRATISTA && !empty($validated['contrato_numero'])) {
            $datos = ['numero_contrato' => $validated['contrato_numero'], 'fecha_inicio' => $validated['contrato_inicio'], 'fecha_fin' => $validated['contrato_fin'], 'activo' => true];
            $actual = \App\Models\CaracterizacionContrato::actualDe($user->id);
            $actual ? $actual->update($datos) : \App\Models\CaracterizacionContrato::create(['usuario_id' => $user->id] + $datos);
        }
    }

    // ─── Usuario: cambiar su propia contraseña ────────────────────────────────
    public function updatePassword(Request $request)
    {
        $request->validate([
            'password_actual'  => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password_actual, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    // ─── ADMIN: asignar secretaría y dependencia a varios usuarios de una vez ──────
    public function asignarDependencia(Request $request)
    {
        $validated = $request->validate([
            'usuario_ids' => 'required|array|min:1|max:500',
            'usuario_ids.*' => 'integer|exists:usuarios,id',
            'caracterizacion_secretaria_id' => 'required|integer|exists:caracterizacion_secretarias,id',
            'caracterizacion_dependencia_id' => 'required|integer|exists:caracterizacion_dependencias,id',
            'caracterizacion_rol' => ['nullable', Rule::in(CaracterizacionPerfil::ROLES)],
        ]);

        $dependencia = \App\Models\CaracterizacionDependencia::with('secretaria')->findOrFail($validated['caracterizacion_dependencia_id']);
        if ((int) $dependencia->secretaria_id !== (int) $validated['caracterizacion_secretaria_id']) {
            return response()->json(['message' => 'La dependencia no pertenece a esa secretaría.'], 422);
        }
        $texto = mb_substr($dependencia->secretaria->nombre.' — '.$dependencia->nombre, 0, 160);

        // Los roles globales solo los asigna un super admin (o el administrador del sistema si aún no existe ninguno).
        if (in_array($validated['caracterizacion_rol'] ?? null, CaracterizacionPerfil::ROLES_GLOBALES, true)) {
            $esSuper = $request->user()->caracterizacionPerfil?->rol === CaracterizacionPerfil::ROL_SUPER_ADMIN;
            abort_unless($esSuper || ! CaracterizacionPerfil::where('rol', CaracterizacionPerfil::ROL_SUPER_ADMIN)->exists(), 403, 'Solo un super administrador puede asignar el rol de administrador.');
        }

        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $dependencia, $texto, &$n) {
            foreach (User::whereIn('id', $validated['usuario_ids'])->get() as $usuario) {
                $usuario->dependencia = $texto;
                $usuario->save();
                // Sin rol solo se actualiza el texto y, si ya tenía perfil, se mueve a la nueva dependencia (conserva su rol).
                $perfil = $usuario->caracterizacionPerfil;
                $rol = $validated['caracterizacion_rol'] ?? $perfil?->rol;
                if ($rol) {
                    CaracterizacionPerfil::updateOrCreate(['usuario_id' => $usuario->id], [
                        'rol' => $rol, 'secretaria_id' => $dependencia->secretaria_id, 'dependencia_id' => $dependencia->id, 'activo' => true,
                    ]);
                }
                $n++;
            }
        });

        return response()->json(['actualizados' => $n, 'dependencia' => $texto]);
    }

    // ─── ADMIN: eliminar usuario ──────────────────────────────────────────────
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 422);
        }

        $user->tokens()->delete(); // revocar todos sus tokens
        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    // ─── Listar roles disponibles ─────────────────────────────────────────────
    public function roles()
    {
        return response()->json(Role::all());
    }

    // ─── Helper: formato de usuario para respuestas ───────────────────────────
    private function formatUser(User $user): array
    {
        return [
            'id'              => $user->id,
            'nombre_completo' => $user->nombre_completo,
            'cargo'           => $user->cargo,
            'dependencia'     => $user->dependencia,
            'email'           => $user->email,
            'telefono'        => $user->telefono,
            'rol_id'          => $user->rol_id,
            'rol_nombre'      => $user->role?->nombre ?? 'Sin rol',
            'caracterizacion_rol'            => $user->caracterizacionPerfil?->rol,
            'caracterizacion_secretaria_id'  => $user->caracterizacionPerfil?->secretaria_id,
            'caracterizacion_dependencia_id' => $user->caracterizacionPerfil?->dependencia_id,
            'contrato' => (function () use ($user) {
                $c = \App\Models\CaracterizacionContrato::actualDe($user->id);

                return $c ? ['numero' => $c->numero_contrato, 'inicio' => $c->fecha_inicio->toDateString(), 'fin' => $c->fecha_fin->toDateString(), 'estado' => $c->estado()] : null;
            })(),
        ];
    }
}
