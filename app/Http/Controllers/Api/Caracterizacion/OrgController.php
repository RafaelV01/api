<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPerfil;
use App\Models\CaracterizacionSecretaria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgController extends Controller
{
    /**
     * Perfil de caracterización del usuario autenticado (rol, secretaría, dependencia).
     * Lo usa el frontend (Header.jsx) para decidir qué navegación mostrar.
     */
    public function miPerfil(Request $request)
    {
        $perfil = $request->user()->caracterizacionPerfil()->with(['secretaria', 'dependencia'])->first();

        if (!$perfil) {
            return response()->json(['rol' => null]);
        }

        return response()->json($perfil);
    }

    // ─── Secretarías ────────────────────────────────────────────────────────
    public function secretarias()
    {
        return response()->json(CaracterizacionSecretaria::with('dependencias')->orderBy('nombre')->get());
    }

    public function crearSecretaria(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:caracterizacion_secretarias,nombre',
        ]);

        return response()->json(CaracterizacionSecretaria::create($validated), 201);
    }

    public function actualizarSecretaria(Request $request, CaracterizacionSecretaria $secretaria)
    {
        $validated = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255', Rule::unique('caracterizacion_secretarias', 'nombre')->ignore($secretaria->id)],
            'activo' => 'sometimes|boolean',
        ]);

        $secretaria->update($validated);

        return response()->json($secretaria);
    }

    // ─── Dependencias ───────────────────────────────────────────────────────
    public function dependencias(Request $request)
    {
        $query = CaracterizacionDependencia::with('secretaria')->orderBy('nombre');

        if ($request->filled('secretaria_id')) {
            $query->where('secretaria_id', $request->integer('secretaria_id'));
        }

        return response()->json($query->get());
    }

    public function crearDependencia(Request $request)
    {
        $validated = $request->validate([
            'secretaria_id' => 'required|exists:caracterizacion_secretarias,id',
            'nombre' => 'required|string|max:255',
        ]);

        return response()->json(CaracterizacionDependencia::create($validated), 201);
    }

    public function actualizarDependencia(Request $request, CaracterizacionDependencia $dependencia)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'activo' => 'sometimes|boolean',
        ]);

        $dependencia->update($validated);

        return response()->json($dependencia);
    }

    // ─── Perfiles (asignación de rol dentro de Caracterización) ────────────
    public function perfiles()
    {
        return response()->json(
            CaracterizacionPerfil::with(['usuario:id,nombre_completo,email', 'secretaria', 'dependencia'])->get()
        );
    }

    public function asignarPerfil(Request $request)
    {
        $validated = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id|unique:caracterizacion_perfiles,usuario_id',
            'rol' => ['required', Rule::in(CaracterizacionPerfil::ROLES)],
            'secretaria_id' => 'nullable|exists:caracterizacion_secretarias,id',
            'dependencia_id' => 'nullable|exists:caracterizacion_dependencias,id|required_if:rol,contratista',
        ]);

        return response()->json(CaracterizacionPerfil::create($validated), 201);
    }

    public function actualizarPerfil(Request $request, CaracterizacionPerfil $perfil)
    {
        $validated = $request->validate([
            'rol' => ['sometimes', Rule::in(CaracterizacionPerfil::ROLES)],
            'secretaria_id' => 'nullable|exists:caracterizacion_secretarias,id',
            'dependencia_id' => 'nullable|exists:caracterizacion_dependencias,id',
            'activo' => 'sometimes|boolean',
        ]);

        $perfil->update($validated);

        return response()->json($perfil);
    }
}
