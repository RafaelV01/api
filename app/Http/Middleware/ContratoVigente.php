<?php

namespace App\Http\Middleware;

use App\Models\CaracterizacionContrato;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bloquea a los contratistas cuyo contrato terminó (o no ha iniciado), aunque su sesión siga abierta. Cerrar sesión siempre se permite. */
class ContratoVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario && ! $request->is('api/logout') && ($motivo = CaracterizacionContrato::motivoSinAcceso($usuario))) {
            return response()->json(['message' => $motivo, 'codigo' => 'contrato_no_vigente'], 403);
        }

        return $next($request);
    }
}
