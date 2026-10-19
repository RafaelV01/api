<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones largas (consolidados, listados FO-PDD-19 de estadísticas) que se generan en segundo plano:
 * el usuario las pide, sigue usando el sistema y las descarga cuando están listas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->json('parametros')->nullable();
            $table->string('estado', 15)->default('pendiente'); // pendiente | procesando | listo | error
            $table->string('archivo', 255)->nullable();
            $table->string('nombre_descarga', 160)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('terminado_en')->nullable();
            $table->timestamps();

            $table->index('estado');
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_exportaciones');
    }
};
