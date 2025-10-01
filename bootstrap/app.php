<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
    $exceptions->renderable(function (AuthenticationException $e, Request $request) {
        // Si la petición es para una ruta de la API (empieza con 'api/')
        if ($request->is('api/*')) {
            // Devuelve una respuesta JSON con error 401
            return response()->json([
                'message' => 'No autenticado.'
            ], 401);
        }
    });
})->create();
