<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parte 2 (ítems 20–32) deja de vivir en caracterizacion_asistentes y pasa a
 * caracterizacion_seguimientos (una fila por actividad, no por ciudadano). Estas
 * columnas NO se eliminan de caracterizacion_asistentes — así no se pierde nada ya
 * capturado en filas existentes ni se rompe algo si algún reporte viejo aún las
 * lee — solo se aseguran como nullable, igual que se hizo con item17/18 en
 * 2026_09_28_090000_make_canal_idioma_nullable_in_caracterizacion_asistentes_table.php.
 *
 * Nota: en el esquema original (2026_09_22_120004_create_caracterizacion_asistentes_table.php)
 * estas columnas YA se crearon con ->nullable(), así que este ALTER es, en la
 * práctica, un no-op defensivo sobre el estado actual de la tabla. Se deja explícito
 * de todas formas, por consistencia con el resto de la migración de Parte 2 y por si
 * alguna migración futura las endurece sin darse cuenta.
 * SQL crudo en vez de Schema::change() para no depender de doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item20_codigo_dane VARCHAR(20) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item21_categoria VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item21_bien_servicio VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item22_descripcion_beneficio VARCHAR(500) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item23_fecha_beneficio DATE NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item24_gestion_inversion VARCHAR(20) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item25_grupo_etareo VARCHAR(30) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item26_sector VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item26_programa VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item26_meta_producto VARCHAR(255) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item27_nombre_proyecto VARCHAR(255) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item28_ods VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item28_ddhh VARCHAR(255) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item28_pilares_paz VARCHAR(100) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item29_politica_publica VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item30_politica_mipg VARCHAR(150) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item31_total_beneficiarios INT UNSIGNED NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item32_acto_tipo VARCHAR(10) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item32_numero VARCHAR(50) NULL');
        DB::statement('ALTER TABLE caracterizacion_asistentes MODIFY item32_fecha DATE NULL');
    }

    public function down(): void
    {
        // No-op: estas columnas ya eran nullable antes de esta migración (ver nota arriba),
        // así que no hay un estado NOT NULL anterior al cual revertir.
    }
};
