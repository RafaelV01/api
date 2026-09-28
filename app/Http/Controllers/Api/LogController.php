<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogActividad;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Listado paginado del log de auditoría del sistema completo (sistema viejo
     * de Reuniones/Asistentes + Caracterización de Ciudadanía), visible solo
     * para el administrador (gate: middleware es.admin en routes/api.php).
     */
    public function index(Request $request)
    {
        $query = LogActividad::query()->with('usuario:id,nombre_completo,email');

        if ($request->filled('usuario_id')) {
            $query->where('usuario_id', $request->input('usuario_id'));
        }

        // Búsqueda libre por el email "snapshot" — funciona incluso si el usuario
        // autor del log fue eliminado después.
        if ($request->filled('usuario')) {
            $query->where('usuario_email', 'like', '%' . $request->input('usuario') . '%');
        }

        if ($request->filled('entidad_tipo')) {
            $query->where('entidad_tipo', $request->input('entidad_tipo'));
        }

        if ($request->filled('accion')) {
            $query->where('accion', $request->input('accion'));
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->input('fecha_hasta'));
        }

        return response()->json(
            $query->latest('created_at')->paginate(50)
        );
    }
}
