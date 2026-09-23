<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_opciones', function (Blueprint $table) {
            $table->id();
            $table->string('categoria', 50);
            // Subagrupación opcional dentro de una categoría (ej. IDIOMA/LENGUA/DIALECTO para item18).
            $table->string('grupo', 50)->nullable();
            $table->string('valor', 255);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['categoria', 'valor']);
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_opciones');
    }
};
