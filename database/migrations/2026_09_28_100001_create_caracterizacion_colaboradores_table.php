<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que el creador de una actividad invite a otros usuarios existentes a
 * ayudarle a diligenciar la Parte 2 (seguimiento) de esa actividad, cuando una
 * sola persona no da abasto. estado es varchar (no ENUM), validado en la app —
 * ver CaracterizacionColaborador::ESTADOS — igual que caracterizacion_perfiles.rol.
 *
 * created_at/responded_at se declaran explícitos (en vez de timestamps()) porque
 * aquí no existe un concepto natural de "updated_at": solo hay un momento en que
 * se creó la invitación y, opcionalmente, un momento en que el invitado respondió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('caracterizacion_actividades')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('invitado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente'); // pendiente|aceptado|rechazado
            $table->timestamp('created_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->unique(['actividad_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_colaboradores');
    }
};
