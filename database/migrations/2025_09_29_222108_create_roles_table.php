<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('roles', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->enum('nombre', ['admin', 'creador', 'participante'])->unique();
        });

        DB::table('roles')->insert([
            ['id' => 1, 'nombre' => 'admin'],
            ['id' => 2, 'nombre' => 'creador'],
            ['id' => 3, 'nombre' => 'participante'],
        ]);
    }
    public function down(): void { Schema::dropIfExists('roles'); }
};