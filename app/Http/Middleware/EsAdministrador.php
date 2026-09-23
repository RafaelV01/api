<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EsAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || $request->user()->rol_id !== 1) {
            return response()->json([
                'message' => 'No tienes permisos para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
