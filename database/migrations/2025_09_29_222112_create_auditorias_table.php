<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // ... en ..._create_auditorias_table.php

public function up(): void
{
    Schema::create('auditoria', function (Blueprint $table) {
        $table->id();
        $table->foreignId('actor_id')->nullable()->constrained('usuarios')->onUpdate('cascade')->onDelete('set null');
        $table->enum('entidad', ['reunion', 'asistente', 'firma', 'codigo']);
        $table->unsignedBigInteger('entidad_id');
        $table->string('accion', 50);
        $table->json('detalle')->nullable();
        $table->ipAddress('ip')->nullable();
        $table->string('user_agent')->nullable();
        $table->timestamp('created_at')->nullable();

        $table->index(['entidad', 'entidad_id']);
        $table->index(['actor_id', 'accion']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
