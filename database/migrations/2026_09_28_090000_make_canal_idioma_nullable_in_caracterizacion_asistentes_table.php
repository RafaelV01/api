<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los ítems 17 (Canal de Comunicación) y 18 (Idiomas/Lenguas/Dialectos) se eliminaron
 * del formulario. Las columnas se dejan en la tabla (no se borran, por si ya hay datos
 * capturados) pero pasan a ser nullable, ya que el registro público ya no las diligencia.
 * SQL crudo en vez de Schema::change() para no depender de doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item17_canal_comunicacion VARCHAR(50) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item18_idioma_lengua_dialecto VARCHAR(100) NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE caracterizacion_asistentes MODIFY item17_canal_comunicacion VARCHAR(50) NOT NULL DEFAULT ''");
        DB::statement("ALTER TABLE caracterizacion_asistentes MODIFY item18_idioma_lengua_dialecto VARCHAR(100) NOT NULL DEFAULT ''");
    }
};
