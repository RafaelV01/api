<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->boolean('allow_guests')->default(false)->after('estado');
        });

        Schema::table('asistentes', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->dropColumn('allow_guests');
        });

        Schema::table('asistentes', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable(false)->change();
        });
    }
};
