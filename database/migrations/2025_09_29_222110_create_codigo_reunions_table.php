<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('codigos_reunion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reunion_id')->unique()->constrained('reuniones')->onUpdate('cascade')->onDelete('cascade');
            $table->string('codigo', 16)->unique();
            $table->dateTime('expira_en')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->constrained('usuarios')->onUpdate('cascade')->onDelete('restrict');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('codigos_reunion'); }
};