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
use App\Http\Controllers\Api\Caracterizacion\EstadisticasVariablesController;
use App\Http\Controllers\Api\Caracterizacion\OpcionController;
use App\Http\Controllers\Api\Caracterizacion\OrgController;
use App\Http\Controllers\Api\Caracterizacion\PublicController as CaracterizacionPublicController;
use App\Http\Controllers\Api\LogController;

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
        Route::post('/usuarios/asignar-dependencia', [UserController::class, 'asignarDependencia']);
        Route::get('/usuarios', [UserController::class, 'index']);
        Route::post('/usuarios', [UserController::class, 'store']);
        Route::get('/usuarios/{id}', [UserController::class, 'show']);
        Route::put('/usuarios/{id}', [UserController::class, 'update']);
        Route::delete('/usuarios/{id}', [UserController::class, 'destroy']);

        // Auditoría — trazabilidad de todo el sistema (Reuniones + Caracterización),
        // incluidas las propias acciones del administrador.
        Route::get('/logs', [LogController::class, 'index']);
    });

    // ─── Caracterización de Ciudadanía — rutas autenticadas ────────────────────
    Route::prefix('caracterizacion')->group(function () {
        Route::get('/mi-perfil', [OrgController::class, 'miPerfil']);

        // Listado mínimo de usuarios para el selector de "invitar colaborador" —
        // a propósito no requiere es.admin, solo un perfil de caracterización activo.
        Route::get('/usuarios-disponibles', [OrgController::class, 'usuariosDisponibles']);

        // Notificaciones del usuario (la campana del encabezado)
        Route::get('/notificaciones', [\App\Http\Controllers\Api\Caracterizacion\NotificacionController::class, 'index']);
        Route::post('/notificaciones/leer-todas', [\App\Http\Controllers\Api\Caracterizacion\NotificacionController::class, 'leerTodas']);
        Route::post('/notificaciones/{id}/leer', [\App\Http\Controllers\Api\Caracterizacion\NotificacionController::class, 'leer']);

        // Exportaciones largas en segundo plano: se piden, se consulta su estado y se descargan cuando están listas.
        Route::post('/exportaciones', [\App\Http\Controllers\Api\Caracterizacion\ExportacionController::class, 'store']);
        Route::get('/exportaciones/{exportacion}', [\App\Http\Controllers\Api\Caracterizacion\ExportacionController::class, 'show']);
        Route::get('/exportaciones/{exportacion}/descargar', [\App\Http\Controllers\Api\Caracterizacion\ExportacionController::class, 'descargar']);

        // Plan de Desarrollo Departamental (sector → programa → meta producto) para la Parte 2.
        Route::get('/plan-desarrollo', [\App\Http\Controllers\Api\Caracterizacion\PlanDesarrolloController::class, 'index']);

        // Contratista/Secretaría/Administrador: cada uno ve lo suyo (scopeVisiblePara).
        Route::get('/actividades', [ActividadController::class, 'index']);
        // Consolidado (todas las actividades visibles + filtros) en un solo Excel
        Route::get('/consolidado/excel', [ActividadController::class, 'consolidadoExcel']);
        Route::post('/actividades', [ActividadController::class, 'store']);
        Route::get('/actividades/{actividad}', [ActividadController::class, 'show']);
        Route::post('/actividades/{actividad}/finalizar', [ActividadController::class, 'finalizar']);
        Route::get('/actividades/{actividad}/pdf', [ActividadController::class, 'generarPdf']);
        Route::get('/actividades/{actividad}/excel', [ActividadController::class, 'generarExcel']);
        Route::put('/actividades/{actividad}/parte2', [ActividadController::class, 'completarParte2']);
        Route::put('/actividades/{actividad}/seguimiento-lote', [ActividadController::class, 'seguimientoLote']);
        Route::put('/asistentes/{asistente}/seguimiento', [ActividadController::class, 'completarParte2Asistente']);
        Route::delete('/asistentes/{asistente}/seguimiento', [ActividadController::class, 'quitarParte2Asistente']);

        // Colaboradores — el creador de una actividad invita a otro usuario a
        // ayudarle a diligenciar la Parte 2; el invitado acepta/rechaza y, si
        // acepta, ve la actividad en "Actividades Compartidas Conmigo".
        Route::post('/actividades/{actividad}/colaboradores', [ActividadController::class, 'invitarColaborador']);
        Route::get('/mis-invitaciones', [ActividadController::class, 'misInvitaciones']);
        Route::post('/colaboraciones/{colaborador}/responder', [ActividadController::class, 'responderInvitacion']);
        Route::get('/mis-colaboraciones', [ActividadController::class, 'misColaboraciones']);

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
            Route::get('/aprobaciones/resumen', [AprobacionController::class, 'resumen']);

            Route::get('/estadisticas', [EstadisticasController::class, 'index']);
            Route::get('/estadisticas/exportar', [EstadisticasController::class, 'exportar']);
            // Plan de Desarrollo administrable (listar, crear, editar/activar, importar CSV o JSON)
            Route::get('/plan-desarrollo/admin', [\App\Http\Controllers\Api\Caracterizacion\PlanDesarrolloController::class, 'admin']);
            Route::post('/plan-desarrollo/admin', [\App\Http\Controllers\Api\Caracterizacion\PlanDesarrolloController::class, 'guardar']);
            Route::post('/plan-desarrollo/admin/importar', [\App\Http\Controllers\Api\Caracterizacion\PlanDesarrolloController::class, 'importar']);
            Route::put('/plan-desarrollo/admin/{id}', [\App\Http\Controllers\Api\Caracterizacion\PlanDesarrolloController::class, 'actualizar']);
            Route::get('/personas', [\App\Http\Controllers\Api\Caracterizacion\PersonaController::class, 'index']);
            Route::get('/personas/{persona}', [\App\Http\Controllers\Api\Caracterizacion\PersonaController::class, 'show']);
            Route::get('/estadisticas/variables', [EstadisticasVariablesController::class, 'variables']);
            Route::get('/estadisticas/por-variables', [EstadisticasVariablesController::class, 'index']);
            Route::get('/estadisticas/por-variables/exportar', [EstadisticasVariablesController::class, 'exportar']);
        });
    });
});
