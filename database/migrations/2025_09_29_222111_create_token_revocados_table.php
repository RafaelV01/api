<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // ... en ..._create_token_revocados_table.php

public function up(): void
{
    Schema::create('tokens_revocados', function (Blueprint $table) {
        $table->id();
        $table->string('jti', 64)->unique();
        $table->dateTime('expira_en');
        $table->timestamp('created_at')->nullable();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_revocados');
    }
};
