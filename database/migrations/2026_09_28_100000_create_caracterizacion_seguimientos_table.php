<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 2 (ítems 20–32, columnas AJ–BC del FO-PDD-19) deja de ser por ciudadano
 * (caracterizacion_asistentes) y pasa a ser UNA sola vez por actividad: la llena
 * el contratista que creó la actividad (o un colaborador invitado y aceptado) para
 * toda la actividad, no por cada fila de ciudadano registrado — de ahí el unique()
 * sobre actividad_id.
 *
 * item25_grupo_etareo NO se incluye aquí a propósito: en el formulario físico es
 * una columna que aparece una vez por cada fila de persona (se calcula a partir de
 * la edad de cada quien, item15_edad, que sigue siendo un dato de Parte 1 por
 * ciudadano). Con Parte 2 ahora a nivel de actividad, un solo grupo etáreo ya no
 * tiene sentido (distintos ciudadanos de la misma actividad pueden tener edades
 * distintas), así que se sigue calculando al vuelo por fila en el PDF/Excel a partir
 * del item15_edad de cada asistente (ver CaracterizacionAsistente::calcularGrupoEtareo()),
 * en vez de guardarse aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->unique()->constrained('caracterizacion_actividades')->cascadeOnDelete();

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
        Schema::dropIfExists('caracterizacion_seguimientos');
    }
};
