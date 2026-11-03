<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato de cada contratista: número y fechas de inicio/fin. Al terminar el contrato se deshabilita el acceso a la plataforma.
 * (Más adelante se le asocian los bloques de seguimiento del contrato.) Solo se crea la tabla; no se modifica nada existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_contratos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->string('numero_contrato', 60);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['usuario_id', 'fecha_fin']);
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_contratos');
    }
};
