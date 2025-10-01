<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('firmas_digitales', function (Blueprint $table) {
            $table->id();
            $table->enum('owner_tipo', ['usuario', 'asistente']);
            $table->unsignedBigInteger('owner_id');
            $table->enum('formato', ['png', 'svg', 'jpg']);
            $table->mediumText('data_base64');
            $table->char('hash_integridad', 64)->unique();
            $table->timestamp('created_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->index(['owner_tipo', 'owner_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('firmas_digitales'); }
};