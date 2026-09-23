<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_objetivo', 20); // actividad|usuario|secretaria|dependencia
            $table->unsignedBigInteger('objetivo_id');
            $table->string('accion', 20); // aprobado|rechazado
            $table->foreignId('actor_id')->constrained('usuarios')->restrictOnDelete();
            $table->string('observacion', 500)->nullable();
            $table->unsignedInteger('actividades_afectadas')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tipo_objetivo', 'objetivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_aprobaciones');
    }
};
