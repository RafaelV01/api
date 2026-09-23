<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_perfiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios')->cascadeOnDelete();
            // Valores validados en la app (administrador|secretaria|contratista) — varchar a propósito,
            // no enum, para poder agregar roles nuevos sin ALTER TABLE.
            $table->string('rol', 20);
            $table->foreignId('secretaria_id')->nullable()->constrained('caracterizacion_secretarias')->nullOnDelete();
            $table->foreignId('dependencia_id')->nullable()->constrained('caracterizacion_dependencias')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_perfiles');
    }
};
