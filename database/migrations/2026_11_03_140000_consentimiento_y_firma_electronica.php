<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Constancia de que la persona aceptó la política de tratamiento de datos (versión y fecha) y de cómo firmó: «dibujada» (lienzo) o
 * «electronica» (alternativa accesible para personas con discapacidad visual: casilla de aceptación + nombre y documento).
 * Nullable: los registros anteriores no tienen esta constancia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->string('consentimiento_version', 20)->nullable();
            $table->timestamp('consentimiento_en')->nullable();
            $table->string('firma_modo', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caracterizacion_asistentes', fn (Blueprint $table) => $table->dropColumn(['consentimiento_version', 'consentimiento_en', 'firma_modo']));
    }
};