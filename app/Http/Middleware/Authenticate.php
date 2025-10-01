<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Esta es la modificación clave para que las APIs devuelvan un error 401
        // en lugar de intentar redirigir.
        return $request->expectsJson() ? null : route('login');
    }
}