<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aquí es donde puedes registrar las rutas web para tu aplicación.
|
*/

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return '<h1>✅ ¡Conexión a la base de datos exitosa!</h1><p>Laravel se ha conectado correctamente a la base de datos: ' . DB::connection()->getDatabaseName() . '</p>';
    } catch (\Exception $e) {
        // die() se usa para mostrar el error exacto y detener todo.
        die('<h1>❌ Error de conexión a la base de datos:</h1><pre>' . $e->getMessage() . '</pre>');
    }
});

Route::get('/', function () {
    // Esta es una respuesta simple para saber que la API está viva.
    return ['status' => 'API de Asistencia - En funcionamiento'];
});