<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personas únicas (por tipo + número de documento). Cada participación en una reunión
 * (caracterizacion_asistentes) sigue guardando lo que la persona declaró ESE día (para que el PDF/Excel de cada
 * actividad quede tal cual se registró), pero ahora apunta a su ficha de persona, que guarda los datos más recientes.
 * Así hay una sola fila por ciudadano aunque haya ido a muchas reuniones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caracterizacion_personas', function (Blueprint $table) {
            $table->id();
            $table->string('persona_clave', 80)->unique();
            $table->string('tipo_documento', 20);
            $table->string('numero_documento', 30)->index();
            $table->string('nombre', 255);
            $table->string('municipio', 50)->nullable()->index();
            $table->string('zona', 10)->nullable();
            $table->string('ubicacion_tipo', 50)->nullable();
            $table->string('ubicacion_detalle', 255)->nullable();
            $table->string('contacto', 20)->nullable();
            $table->string('genero', 40)->nullable();
            $table->string('etnico', 80)->nullable();
            $table->string('sector_organizacion', 255)->nullable();
            $table->string('clasificacion_organizacion', 50)->nullable();
            $table->unsignedTinyInteger('edad')->nullable();
            $table->unsignedTinyInteger('tamano_grupo_familiar')->nullable();
            $table->string('cargo_barrio_vereda', 255)->nullable();
            $table->unsignedInteger('participaciones')->default(0);
            $table->timestamp('ultima_participacion_en')->nullable();
            $table->timestamps();
        });

        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->foreignId('persona_id')->nullable()->after('actividad_id')
                ->constrained('caracterizacion_personas')->nullOnDelete();
        });

        // Relleno con lo que ya existe: una persona por documento, con los datos de su participación más reciente.
        DB::statement("
            INSERT INTO caracterizacion_personas
                (persona_clave, tipo_documento, numero_documento, nombre, municipio, zona, ubicacion_tipo, ubicacion_detalle, contacto, genero,
                 etnico, sector_organizacion, clasificacion_organizacion, edad, tamano_grupo_familiar, cargo_barrio_vereda, created_at, updated_at)
            SELECT a.persona_clave, UPPER(TRIM(a.item2_tipo_documento)), TRIM(a.item3_numero_documento), a.item1_nombre, a.item5_municipio, a.item6_zona,
                   a.item7_ubicacion_tipo, a.item7_ubicacion_detalle, a.item8_contacto_valor, a.item9_genero, a.item10_etnico,
                   a.item12_sector_organizacion, a.item13_clasificacion_organizacion, a.item15_edad, a.item16_tamano_grupo_familiar,
                   a.item4_cargo_barrio_vereda, NOW(), NOW()
            FROM caracterizacion_asistentes a
            JOIN (SELECT MAX(id) AS id FROM caracterizacion_asistentes GROUP BY persona_clave) ultimo ON ultimo.id = a.id
        ");
        DB::statement('UPDATE caracterizacion_asistentes a JOIN caracterizacion_personas p ON p.persona_clave = a.persona_clave SET a.persona_id = p.id');
        DB::statement('
            UPDATE caracterizacion_personas p
            JOIN (SELECT persona_id, COUNT(*) AS n, MAX(created_at) AS ultima FROM caracterizacion_asistentes GROUP BY persona_id) x ON x.persona_id = p.id
            SET p.participaciones = x.n, p.ultima_participacion_en = x.ultima
        ');
    }

    public function down(): void
    {
        Schema::table('caracterizacion_asistentes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
        });
        Schema::dropIfExists('caracterizacion_personas');
    }
};
