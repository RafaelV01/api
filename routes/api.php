<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReunionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\Caracterizacion\ActividadController;
use App\Http\Controllers\Api\Caracterizacion\AprobacionController;
use App\Http\Controllers\Api\Caracterizacion\EstadisticasController;
use App\Http\Controllers\Api\Caracterizacion\OpcionController;
use App\Http\Controllers\Api\Caracterizacion\OrgController;
use App\Http\Controllers\Api\Caracterizacion\PublicController as CaracterizacionPublicController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ─── Rutas Públicas ───────────────────────────────────────────────────────────
// Máximo 5 intentos por minuto por IP para prevenir brute force
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/register', [AuthController::class, 'register']);
});

// Asistencia pública (sin token)
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/asistencia/info/{codigo}', [AsistenciaController::class, 'info']);
    Route::post('/asistencia/unirse/{codigo}', [AsistenciaController::class, 'unirse']);
});

// ─── Caracterización de Ciudadanía (FO-PDD-19) — Parte 1, pública (el ciudadano se autoregistra) ──
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/caracterizacion/info/{slug}', [CaracterizacionPublicController::class, 'info']);
    Route::post('/caracterizacion/registrar/{slug}', [CaracterizacionPublicController::class, 'registrarCiudadano']);
    Route::get('/caracterizacion/opciones', [OpcionController::class, 'index']);
});


// ─── Rutas Protegidas (requieren token) ───────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Autenticación
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn(Request $request) => $request->user());

    // Perfil propio (cualquier usuario autenticado)
    Route::get('/perfil', [UserController::class, 'perfil']);
    Route::put('/perfil', [UserController::class, 'actualizarPerfil']);
    Route::put('/perfil/password', [UserController::class, 'updatePassword']);

    // Reuniones
    Route::post('/reuniones', [ReunionController::class, 'store']);
    Route::get('/reuniones', [ReunionController::class, 'index']);
    Route::get('/reuniones/{reunion}', [ReunionController::class, 'show']);
    Route::post('/reuniones/{reunion}/finalizar', [ReunionController::class, 'finalizar']);
    Route::get('/reuniones/{reunion}/pdf', [ReunionController::class, 'generarPdf']);

    // ─── Solo Administrador ───────────────────────────────────────────────────
    Route::middleware('es.admin')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/usuarios', [UserController::class, 'index']);
        Route::post('/usuarios', [UserController::class, 'store']);
        Route::get('/usuarios/{id}', [UserController::class, 'show']);
        Route::put('/usuarios/{id}', [UserController::class, 'update']);
        Route::delete('/usuarios/{id}', [UserController::class, 'destroy']);
    });

    // ─── Caracterización de Ciudadanía — rutas autenticadas ────────────────────
    Route::prefix('caracterizacion')->group(function () {
        Route::get('/mi-perfil', [OrgController::class, 'miPerfil']);

        // Contratista/Secretaría/Administrador: cada uno ve lo suyo (scopeVisiblePara).
        Route::get('/actividades', [ActividadController::class, 'index']);
        Route::post('/actividades', [ActividadController::class, 'store']);
        Route::get('/actividades/{actividad}', [ActividadController::class, 'show']);
        Route::post('/actividades/{actividad}/finalizar', [ActividadController::class, 'finalizar']);
        Route::get('/actividades/{actividad}/pdf', [ActividadController::class, 'generarPdf']);
        Route::get('/actividades/{actividad}/excel', [ActividadController::class, 'generarExcel']);
        Route::put('/actividades/{actividad}/asistentes/{asistente}/parte2', [ActividadController::class, 'completarParte2']);

        // ─── Solo Administrador ─────────────────────────────────────────────
        Route::middleware('caracterizacion.rol:administrador')->group(function () {
            Route::get('/secretarias', [OrgController::class, 'secretarias']);
            Route::post('/secretarias', [OrgController::class, 'crearSecretaria']);
            Route::put('/secretarias/{secretaria}', [OrgController::class, 'actualizarSecretaria']);

            Route::get('/dependencias', [OrgController::class, 'dependencias']);
            Route::post('/dependencias', [OrgController::class, 'crearDependencia']);
            Route::put('/dependencias/{dependencia}', [OrgController::class, 'actualizarDependencia']);

            Route::get('/perfiles', [OrgController::class, 'perfiles']);
            Route::post('/perfiles', [OrgController::class, 'asignarPerfil']);
            Route::put('/perfiles/{perfil}', [OrgController::class, 'actualizarPerfil']);

            Route::get('/opciones/admin', [OpcionController::class, 'admin']);
            Route::post('/opciones', [OpcionController::class, 'store']);
            Route::put('/opciones/{opcion}', [OpcionController::class, 'update']);
            Route::delete('/opciones/{opcion}', [OpcionController::class, 'destroy']);

            Route::post('/aprobaciones', [AprobacionController::class, 'resolver']);
            Route::get('/aprobaciones', [AprobacionController::class, 'historial']);

            Route::get('/estadisticas', [EstadisticasController::class, 'index']);
        });
    });
});
