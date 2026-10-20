<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La firma del ciudadano pasa a guardarse como archivo (ruta en `firma_ciudadano_archivo`). La columna base64 se
 * conserva (ahora opcional) para las firmas anteriores hasta que `caracterizacion:migrar-firmas` las convierta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->string('firma_ciudadano_archivo', 255)->nullable()->after('firma_ciudadano_hash');
            $table->mediumText('firma_ciudadano_base64')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin reversión: volver a exigir la columna base64 fallaría con las filas que ya solo tienen archivo.
    }
};
