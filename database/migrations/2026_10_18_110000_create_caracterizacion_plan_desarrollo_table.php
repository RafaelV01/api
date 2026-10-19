<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de Desarrollo Departamental de Casanare: sector (26.1) → programa (26.2) → meta
 * producto (26.3) del seguimiento. Una fila por meta; los desplegables en cascada del
 * formulario salen de aquí. Sin ENUM: texto + `activo` para retirar entradas sin borrarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_plan_desarrollo', function (Blueprint $table) {
            $table->id();
            $table->string('sector', 150);
            $table->string('programa', 255);
            $table->string('meta_producto', 255);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['sector', 'programa', 'meta_producto'], 'plan_desarrollo_unico');
            $table->index(['sector', 'programa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caracterizacion_plan_desarrollo');
    }
};
