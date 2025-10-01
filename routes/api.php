    <?php

    use App\Http\Controllers\Api\AuthController;
    use App\Http\Controllers\Api\ReunionController;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Route;
    use App\Http\Controllers\Api\AsistenciaController;
    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    */

    // Rutas Públicas
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->name('login'); // // 
Route::get('/asistencia/info/{codigo}', [AsistenciaController::class, 'info']);


    // Rutas Protegidas (requieren token)
    Route::middleware('auth:sanctum')->group(function () {
        
        // Rutas de Autenticación
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // Rutas para Reuniones
        Route::post('/reuniones', [ReunionController::class, 'store']);
        Route::get('/reuniones', [ReunionController::class, 'index']);
        Route::get('/reuniones/{reunion}', [ReunionController::class, 'show']);
        
        
        
    });

    Route::middleware('auth:sanctum')->group(function () {
    // ... (rutas de logout, user, reuniones)

    Route::post('/asistencia/unirse/{codigo}', [AsistenciaController::class, 'unirse']);
    
Route::get('/reuniones/{reunion}/pdf', [ReunionController::class, 'generarPdf']);
});
    