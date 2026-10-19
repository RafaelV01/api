<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendimiento con muchos registros: `persona_clave` (tipo + número de documento, calculada por la base de datos)
 * permite contar personas únicas con un índice en vez de calcular un CONCAT por cada fila, y se agregan los
 * índices de las columnas por las que más se filtra y se ordena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->string('persona_clave', 80)->nullable()
                ->storedAs("CONCAT(UPPER(TRIM(item2_tipo_documento)), '|', TRIM(item3_numero_documento))");
            $table->index('persona_clave', 'asistentes_persona_clave_idx');
            $table->index('item5_municipio', 'asistentes_municipio_idx');
        });

        Schema::table('caracterizacion_actividades', function (Blueprint $table) {
            $table->index(['estado_aprobacion', 'fecha'], 'actividades_aprobacion_fecha_idx');
            $table->index('municipio', 'actividades_municipio_idx');
            $table->index('created_at', 'actividades_creada_idx');
        });
    }

    public function down(): void
    {
        Schema::table('caracterizacion_actividades', function (Blueprint $table) {
            $table->dropIndex('actividades_aprobacion_fecha_idx');
            $table->dropIndex('actividades_municipio_idx');
            $table->dropIndex('actividades_creada_idx');
        });
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->dropIndex('asistentes_persona_clave_idx');
            $table->dropIndex('asistentes_municipio_idx');
            $table->dropColumn('persona_clave');
        });
    }
};
