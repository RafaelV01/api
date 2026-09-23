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
    public function index()
    {
        $usuarios = User::with(['role', 'caracterizacionPerfil'])
            ->orderBy('nombre_completo')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json($usuarios);
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
            'dependencia'     => $request->dependencia,
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

    // ─── ADMIN: asigna/actualiza el perfil de Caracterización de un usuario ───
    private function guardarPerfilCaracterizacion(Request $request, User $user): void
    {
        $validated = $request->validate([
            'caracterizacion_rol' => ['nullable', Rule::in(CaracterizacionPerfil::ROLES)],
            'caracterizacion_secretaria_id' => 'nullable|integer|exists:caracterizacion_secretarias,id',
            'caracterizacion_dependencia_id' => 'nullable|integer|exists:caracterizacion_dependencias,id|required_if:caracterizacion_rol,'.CaracterizacionPerfil::ROL_CONTRATISTA,
        ]);

        if (!$validated['caracterizacion_rol']) {
            $user->caracterizacionPerfil()->delete();
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
        ];
    }
}
