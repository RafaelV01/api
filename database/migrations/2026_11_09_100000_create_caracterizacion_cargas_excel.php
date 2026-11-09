<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estadísticas de archivos Excel: el administrador sube archivos en el formato FO-PDD-19 y se sacan estadísticas SOLO con ellos.
 * Estas tablas son independientes de las reuniones del sistema (nada se mezcla): una carga = un archivo; sus registros = una fila de
 * persona por fila del Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_cargas_excel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('nombre', 120);
            $table->string('archivo_original', 255);
            $table->string('archivo_ruta', 255)->nullable();
            $table->unsignedInteger('total_filas')->default(0);
            $table->unsignedInteger('filas_omitidas')->default(0);
            $table->json('omitidas_detalle')->nullable();
            $table->timestamps();
        });

        Schema::create('caracterizacion_carga_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carga_id')->constrained('caracterizacion_cargas_excel')->cascadeOnDelete();
            $table->unsignedInteger('fila');
            $table->string('persona_clave', 60);
            $table->string('nombre', 255)->nullable();
            $table->string('tipo_documento', 12)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('municipio', 60)->nullable();
            $table->string('zona', 10)->nullable();
            $table->string('genero', 30)->nullable();
            $table->string('etnico', 60)->nullable();
            $table->string('sector', 150)->nullable();
            $table->string('clasificacion', 30)->nullable();
            $table->string('problematicas', 150)->nullable(); // «|Salud|Ambiental|» para filtrar con LIKE
            $table->string('enfoques', 150)->nullable();
            $table->unsignedTinyInteger('edad')->nullable();
            $table->unsignedTinyInteger('tamano_grupo_familiar')->nullable();

            $table->string('item21_categoria', 150)->nullable();
            $table->string('item21_bien_servicio', 150)->nullable();
            $table->string('item22_descripcion_beneficio', 500)->nullable();
            $table->date('item23_fecha_beneficio')->nullable();
            $table->string('item24_gestion_inversion', 20)->nullable();
            $table->string('item26_sector', 150)->nullable();
            $table->string('item26_programa', 255)->nullable();
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

            $table->index(['carga_id', 'persona_clave']);
            $table->index('persona_clave');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_carga_registros');
        Schema::dropIfExists('caracterizacion_cargas_excel');
    }
};
