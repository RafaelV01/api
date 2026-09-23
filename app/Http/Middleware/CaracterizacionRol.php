<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaracterizacionRol
{
    public function handle(Request $request, Closure $next, string ...$rolesPermitidos): Response
    {
        $perfil = $request->user()?->caracterizacionPerfil;

        if (!$perfil || !$perfil->activo || !in_array($perfil->rol, $rolesPermitidos, true)) {
            return response()->json([
                'message' => 'No tienes permisos para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
