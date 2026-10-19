<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las metas de producto del Plan de Desarrollo llegan a ~700 caracteres y los programas a ~170:
 * se amplían las columnas del catálogo y del seguimiento para no truncar texto del plan, y se
 * quita el índice único por texto (no cabe en el límite de índice con columnas tan anchas; el
 * número de meta del plan, `orden`, identifica cada fila).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_plan_desarrollo', function (Blueprint $table) {
            $table->dropUnique('plan_desarrollo_unico');
        });
        Schema::table('caracterizacion_plan_desarrollo', function (Blueprint $table) {
            $table->string('programa', 255)->change();
            $table->string('meta_producto', 800)->change();
            $table->index('orden');
        });

        foreach (['caracterizacion_seguimientos', 'caracterizacion_seguimiento_asistentes'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('item26_programa', 255)->nullable()->change();
                $table->string('item26_meta_producto', 800)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Sin reversión: reducir las columnas podría truncar datos ya guardados.
    }
};
