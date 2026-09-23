<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_actividades', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 16)->unique();
            $table->string('slug_acceso', 255)->unique();
            $table->string('tema', 255);
            $table->date('fecha');
            $table->time('hora');
            $table->string('quien_dirige_expone', 255);
            $table->mediumText('firma_expositor_base64')->nullable();
            $table->char('firma_expositor_hash', 64)->nullable();
            $table->string('municipio', 50);
            $table->string('departamento', 50)->default('Casanare');
            // asistencia_tecnica|capacitacion|socializacion|otro (banner AI1:AI4 del Excel)
            $table->string('tipo_evento', 30);
            $table->string('otro_evento_detalle', 300)->nullable();

            $table->foreignId('creador_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('dependencia_id')->constrained('caracterizacion_dependencias')->restrictOnDelete();
            $table->foreignId('secretaria_id')->constrained('caracterizacion_secretarias')->restrictOnDelete();

            $table->string('estado', 20)->default('activa'); // activa|cerrada
            $table->string('estado_aprobacion', 20)->default('pendiente'); // pendiente|aprobada|rechazada
            $table->foreignId('aprobado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->string('observacion_aprobacion', 500)->nullable();

            $table->timestamps();

            // secretaria_id, dependencia_id y creador_id ya quedan indexados por sus FKs (constrained()).
            $table->index('fecha');
            $table->index('estado_aprobacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_actividades');
    }
};
