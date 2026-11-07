<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Inicio de sesión: el límite es por correo + IP (antes era solo por IP: varias personas detrás de la misma red se bloqueaban entre sí).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Formularios públicos (registro de ciudadanos): en una reunión muchas personas comparten la misma red/IP y cada formulario hace varias
        // consultas; el tope por IP es amplio para no bloquear a quien llega de último, y cada documento ya tiene su propio freno.
        RateLimiter::for('publico', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
    }
}
