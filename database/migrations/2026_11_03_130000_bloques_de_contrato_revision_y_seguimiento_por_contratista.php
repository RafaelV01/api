<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Bloques del contrato (1 a 3 por contrato): la información de seguimiento (ítems 21–32) que sale del contrato del contratista
 *    y se copia a cada reunión que crea.
 * 2) El seguimiento de una reunión pasa a ser POR CONTRATISTA (unique actividad+usuario): en una reunión con varios contratistas cada
 *    uno aporta el suyo. Las filas anteriores se asignan al creador de su reunión.
 * 3) Datos del flujo de revisión (cuándo se envió y quién la aprobó como referente). Solo se agregan columnas/tablas; no se borra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_contrato_bloques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('caracterizacion_contratos')->cascadeOnDelete();
            $table->unsignedTinyInteger('orden')->default(1);
            $table->string('nombre', 80)->nullable();

            $table->string('item21_categoria', 150)->nullable();
            $table->string('item21_bien_servicio', 150)->nullable();
            $table->string('item22_descripcion_beneficio', 500)->nullable();
            $table->date('item23_fecha_beneficio')->nullable();
            $table->string('item24_gestion_inversion', 20)->nullable();
            $table->string('item26_sector', 150)->nullable();
            $table->string('item26_programa', 150)->nullable();
            $table->string('item26_meta_producto', 800)->nullable();
            $table->string('item27_nombre_proyecto', 255)->nullable();
            $table->string('item28_ods', 150)->nullable();
            $table->string('item28_ddhh', 255)->nullable();
            $table->string('item28_pilares_paz', 100)->nullable();
            $table->string('item29_politica_publica', 150)->nullable();
            $table->string('item30_politica_mipg', 150)->nullable();
            $table->string('item32_acto_tipo', 10)->nullable();
            $table->string('item32_numero', 50)->nullable();
            $table->date('item32_fecha')->nullable();
            $table->timestamps();

            $table->unique(['contrato_id', 'orden']);
        });

        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable()->after('actividad_id');
            $table->unsignedBigInteger('contrato_bloque_id')->nullable()->after('usuario_id');
        });
        DB::statement('UPDATE caracterizacion_seguimientos s JOIN caracterizacion_actividades a ON a.id = s.actividad_id SET s.usuario_id = a.creador_id');

        // Primero el índice nuevo (la llave foránea de actividad_id lo puede usar) y luego se quita el anterior.
        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->unique(['actividad_id', 'usuario_id'], 'seguimientos_actividad_usuario_unique');
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            $table->foreign('contrato_bloque_id')->references('id')->on('caracterizacion_contrato_bloques')->nullOnDelete();
        });
        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->dropUnique('caracterizacion_seguimientos_actividad_id_unique');
        });

        Schema::table('caracterizacion_actividades', function (Blueprint $table) {
            $table->timestamp('enviada_en')->nullable();
            $table->unsignedBigInteger('referente_aprobo_por')->nullable();
            $table->timestamp('referente_aprobo_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caracterizacion_actividades', fn (Blueprint $table) => $table->dropColumn(['enviada_en', 'referente_aprobo_por', 'referente_aprobo_en']));
        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->dropForeign(['contrato_bloque_id']);
        });
        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->unique('actividad_id', 'caracterizacion_seguimientos_actividad_id_unique');
        });
        Schema::table('caracterizacion_seguimientos', function (Blueprint $table) {
            $table->dropUnique('seguimientos_actividad_usuario_unique');
            $table->dropColumn(['usuario_id', 'contrato_bloque_id']);
        });
        Schema::dropIfExists('caracterizacion_contrato_bloques');
    }
};
