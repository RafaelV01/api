<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('asistentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reunion_id')->constrained('reuniones')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->onUpdate('cascade')->onDelete('set null');
            $table->string('nombre_completo', 160);
            $table->string('cargo', 120);
            $table->string('dependencia', 160);
            $table->string('email', 190);
            $table->string('telefono', 30);
            $table->foreignId('firma_id')->nullable()->constrained('firmas_digitales')->onUpdate('cascade')->onDelete('set null');
            $table->boolean('validado')->default(true);
            $table->foreignId('observado_por')->nullable()->constrained('usuarios')->onUpdate('cascade')->onDelete('set null');
            $table->string('observacion')->nullable();
            $table->enum('creado_via', ['codigo', 'enlace', 'qr', 'manual']);
            $table->ipAddress('ip_origen')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['reunion_id', 'validado']);
            $table->index('email');
            $table->index('usuario_id');
        });
    }
    public function down(): void { Schema::dropIfExists('asistentes'); }
};