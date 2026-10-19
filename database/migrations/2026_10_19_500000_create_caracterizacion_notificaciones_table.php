<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Avisos dentro de la aplicación: actividad aprobada o rechazada, invitaciones a colaborar y sus respuestas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('titulo', 160);
            $table->string('mensaje', 500)->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamp('leida_en')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['usuario_id', 'leida_en', 'created_at'], 'notificaciones_usuario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_notificaciones');
    }
};
