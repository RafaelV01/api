<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ajustes_reunion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reunion_id')->unique()->constrained('reuniones')->onUpdate('cascade')->onDelete('cascade');
            $table->boolean('mostrar_lista_a_participantes')->default(false);
            $table->boolean('permitir_autoregistro')->default(true);
            $table->boolean('requerir_login_para_unirse')->default(false);
            $table->boolean('permitir_edicion_por_creador')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('ajustes_reunion'); }
};