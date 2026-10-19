<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vigencia del plan (p. ej. «2024-2027»): permite cargar el siguiente plan de gobierno sin mezclar ni perder el anterior. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracterizacion_plan_desarrollo', function (Blueprint $table) {
            $table->string('vigencia', 20)->default('2024-2027')->after('meta_producto');
            $table->index('vigencia');
        });
    }

    public function down(): void
    {
        Schema::table('caracterizacion_plan_desarrollo', function (Blueprint $table) {
            $table->dropIndex(['vigencia']);
            $table->dropColumn('vigencia');
        });
    }
};
