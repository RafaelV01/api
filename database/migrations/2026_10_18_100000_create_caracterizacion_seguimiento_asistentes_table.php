<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 2 (ítems 20–32) POR CIUDADANO. El seguimiento general de la actividad
 * (caracterizacion_seguimientos) sigue siendo el valor por defecto de todos los
 * ciudadanos; esta tabla guarda, solo para quien lo necesite, una versión propia
 * (otro bien/servicio, otra categoría, otra fecha de beneficio, etc.). Si un
 * ciudadano no tiene fila aquí, el PDF/Excel usan el seguimiento de la actividad.
 * El grupo etáreo (ítem 25) no se guarda: se calcula de la edad de cada persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_seguimiento_asistentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistente_id')->unique()->constrained('caracterizacion_asistentes')->cascadeOnDelete();
            $table->string('item20_codigo_dane', 20)->nullable();
            $table->string('item21_categoria', 150)->nullable();
            $table->string('item21_bien_servicio', 150)->nullable();
            $table->string('item22_descripcion_beneficio', 500)->nullable();
            $table->date('item23_fecha_beneficio')->nullable();
            $table->string('item24_gestion_inversion', 20)->nullable();
            $table->string('item26_sector', 150)->nullable();
            $table->string('item26_programa', 150)->nullable();
            $table->string('item26_meta_producto', 255)->nullable();
            $table->string('item27_nombre_proyecto', 255)->nullable();
            $table->string('item28_ods', 150)->nullable();
            $table->string('item28_ddhh', 255)->nullable();
            $table->string('item28_pilares_paz', 100)->nullable();
            $table->string('item29_politica_publica', 150)->nullable();
            $table->string('item30_politica_mipg', 150)->nullable();
            $table->unsignedInteger('item31_total_beneficiarios')->nullable();
            $table->string('item32_acto_tipo', 10)->nullable();
            $table->string('item32_numero', 50)->nullable();
            $table->date('item32_fecha')->nullable();
            $table->foreignId('completado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('completado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_seguimiento_asistentes');
    }
};
