<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_actividad', function (Blueprint $table) {
            $table->id();
            // Nullable: algunas acciones pueden ser disparadas por el sistema (sin usuario
            // autenticado, p. ej. autoregistro público de ciudadanía) o el usuario autor
            // podría eliminarse más adelante — nullOnDelete() preserva el log.
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            // Snapshot del email al momento de escribir el log, para que siga siendo legible
            // aunque el usuario luego se elimine o cambie de correo.
            $table->string('usuario_email', 190)->nullable();
            // varchar, no ENUM (principio del proyecto): valores típicos creado|actualizado|eliminado.
            $table->string('accion', 20);
            // Nombre corto de la clase del modelo (class_basename), p. ej. CaracterizacionActividad.
            $table->string('entidad_tipo', 100);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Los logs son inmutables: solo created_at, sin updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index('usuario_id');
            $table->index('entidad_tipo');
            $table->index('entidad_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_actividad');
    }
};
