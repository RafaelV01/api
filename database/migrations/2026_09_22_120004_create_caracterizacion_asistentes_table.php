<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_asistentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('caracterizacion_actividades')->cascadeOnDelete();

            // ── Parte 1 (ítems 1–19, columnas A–AI) — la llena el ciudadano ──────────────
            $table->string('item1_nombre', 255);
            $table->string('item2_tipo_documento', 20);
            $table->string('item3_numero_documento', 30);
            $table->string('item4_cargo_barrio_vereda', 255);
            $table->string('item5_municipio', 50);
            $table->string('item6_zona', 10); // urbana|rural
            $table->string('item7_ubicacion_tipo', 30);
            $table->string('item7_ubicacion_detalle', 255)->nullable();
            $table->string('item8_contacto_tipo', 20);
            $table->string('item8_contacto_valor', 30);
            $table->string('item9_genero', 30);
            $table->string('item10_etnico', 50)->nullable();
            $table->string('item12_sector_organizacion', 150)->nullable();
            $table->string('item13_clasificacion_organizacion', 30)->nullable();
            $table->unsignedTinyInteger('item15_edad');
            $table->unsignedTinyInteger('item16_tamano_grupo_familiar');
            $table->string('item17_canal_comunicacion', 50);
            $table->string('item18_idioma_lengua_dialecto', 100);
            $table->mediumText('firma_ciudadano_base64');
            $table->char('firma_ciudadano_hash', 64);
            $table->string('ip_origen', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // ── Parte 2 (ítems 20–32, columnas AJ–BC) — la llena el contratista después ──
            $table->string('item20_codigo_dane', 20)->nullable();
            $table->string('item21_categoria', 150)->nullable();
            $table->string('item21_bien_servicio', 150)->nullable();
            $table->string('item22_descripcion_beneficio', 500)->nullable();
            $table->date('item23_fecha_beneficio')->nullable();
            $table->string('item24_gestion_inversion', 20)->nullable();
            // Calculado en servidor a partir de item15_edad — nunca editable por el usuario.
            $table->string('item25_grupo_etareo', 30)->nullable();
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

            $table->foreignId('part2_completado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('part2_completado_en')->nullable();

            $table->timestamps();

            $table->index('item5_municipio');
            $table->index('item25_grupo_etareo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_asistentes');
    }
};
