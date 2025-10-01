<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('reuniones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 16)->unique();
            $table->string('slug_acceso', 64)->unique();
            $table->string('tema', 200);
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin')->nullable();
            $table->string('dependencia_lugar', 200);
            $table->string('ciudad_municipio', 120);
            $table->enum('tipo_evento', ['capacitacion', 'divulgacion', 'otro']);
            $table->string('expositor', 160);
            $table->foreignId('firma_creador_id')->nullable()->constrained('firmas_digitales')->onUpdate('cascade')->onDelete('set null');
            $table->foreignId('creador_id')->constrained('usuarios')->onUpdate('cascade')->onDelete('restrict');
            $table->string('qr_png_path')->nullable();
            $table->enum('estado', ['borrador', 'activa', 'cerrada', 'archivada'])->default('borrador');
            $table->timestamps();
            
            $table->index('fecha');
            $table->index('estado');
        });
    }
    public function down(): void { Schema::dropIfExists('reuniones'); }
};