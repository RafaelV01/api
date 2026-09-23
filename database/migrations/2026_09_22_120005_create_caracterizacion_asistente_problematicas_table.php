<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_asistente_problematicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistente_id')->constrained('caracterizacion_asistentes')->cascadeOnDelete();
            $table->string('valor', 30); // Institucional|Ambiental|Salud|Cultural|Educativa
            $table->timestamps();

            $table->unique(['asistente_id', 'valor'], 'caract_asist_problematicas_asist_valor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_asistente_problematicas');
    }
};
