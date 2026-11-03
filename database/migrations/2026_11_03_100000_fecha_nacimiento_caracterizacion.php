<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La edad deja de pedirse: se pide la fecha de nacimiento y la edad se calcula. Se agrega a la ficha de la persona y a cada
 * participación (la edad de la participación queda calculada al día de la reunión y se sigue guardando en item15_edad para
 * que las estadísticas existentes no cambien). Nullable: las fichas anteriores no la tienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_personas', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('nombre');
        });
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable()->after('item13_clasificacion_organizacion');
        });
    }

    public function down(): void
    {
        Schema::table('caracterizacion_asistentes', fn (Blueprint $table) => $table->dropColumn('fecha_nacimiento'));
        Schema::table('caracterizacion_personas', fn (Blueprint $table) => $table->dropColumn('fecha_nacimiento'));
    }
};
