<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_dependencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secretaria_id')->constrained('caracterizacion_secretarias')->cascadeOnDelete();
            $table->string('nombre', 255);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['secretaria_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_dependencias');
    }
};
