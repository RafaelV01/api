<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionOpcion;
use Illuminate\Http\Request;

class OpcionController extends Controller
{
    /**
     * Lectura pública (para cualquier usuario autenticado, incluido el ciudadano
     * anónimo en el formulario de autoregistro) de las opciones activas de una
     * categoría, para poblar los <select> del formulario.
     */
    public function index(Request $request)
    {
        $request->validate(['categoria' => 'required|string|max:50']);

        $opciones = CaracterizacionOpcion::categoria($request->string('categoria'))->get(['id', 'grupo', 'valor', 'orden']);

        return response()->json($opciones);
    }

    /**
     * Listado completo (incluye inactivas) para la pantalla de administración del catálogo.
     */
    public function admin(Request $request)
    {
        $query = CaracterizacionOpcion::query()->orderBy('categoria')->orderBy('orden');

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->string('categoria'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'categoria' => 'required|string|max:50',
            'grupo' => 'nullable|string|max:50',
            'valor' => 'required|string|max:255',
            'orden' => 'nullable|integer',
            'activo' => 'nullable|boolean',
        ]);

        return response()->json(CaracterizacionOpcion::create($validated), 201);
    }

    public function update(Request $request, CaracterizacionOpcion $opcion)
    {
        $validated = $request->validate([
            'categoria' => 'sometimes|string|max:50',
            'grupo' => 'nullable|string|max:50',
            'valor' => 'sometimes|string|max:255',
            'orden' => 'sometimes|integer',
            'activo' => 'sometimes|boolean',
        ]);

        $opcion->update($validated);

        return response()->json($opcion);
    }

    public function destroy(CaracterizacionOpcion $opcion)
    {
        $opcion->delete();

        return response()->json(['message' => 'Opción eliminada.']);
    }
}
