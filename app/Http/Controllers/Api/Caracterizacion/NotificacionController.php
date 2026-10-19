<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionNotificacion;
use Illuminate\Support\Facades\Auth;

/** Notificaciones del usuario autenticado (cada quien solo ve y marca las suyas). */
class NotificacionController extends Controller
{
    /** Las 20 más recientes y el total sin leer (lo que muestra la campana). */
    public function index()
    {
        $propias = CaracterizacionNotificacion::where('usuario_id', Auth::id());

        return response()->json([
            'no_leidas' => (clone $propias)->whereNull('leida_en')->count(),
            'notificaciones' => (clone $propias)->orderByDesc('id')->limit(20)->get(['id', 'tipo', 'titulo', 'mensaje', 'url', 'leida_en', 'created_at']),
        ]);
    }

    public function leer(int $id)
    {
        CaracterizacionNotificacion::where('usuario_id', Auth::id())->where('id', $id)->whereNull('leida_en')->update(['leida_en' => now()]);

        return response()->json(['ok' => true]);
    }

    public function leerTodas()
    {
        CaracterizacionNotificacion::where('usuario_id', Auth::id())->whereNull('leida_en')->update(['leida_en' => now()]);

        return response()->json(['ok' => true]);
    }
}
