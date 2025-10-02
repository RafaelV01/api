<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceCorsHeaders
{
    public function handle(Request $request, Closure $next)
    {
        // Obtenemos la respuesta, sea cual sea (una página de éxito o una de error)
        $response = $next($request);

        // Añadimos las cabeceras de CORS manualmente a CUALQUIER respuesta
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS, PUT, DELETE');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, Application');

        return $response;
    }
}