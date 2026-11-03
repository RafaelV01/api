<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jerarquía de roles nueva: el rol «secretaria» (varchar, sin ENUM) pasa a llamarse «supervisor». Se suman «super_admin» y
 * «referente», que no requieren cambios de esquema. Solo se renombra el valor; nadie pierde acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('caracterizacion_perfiles')->where('rol', 'secretaria')->update(['rol' => 'supervisor']);
    }

    public function down(): void
    {
        DB::table('caracterizacion_perfiles')->where('rol', 'supervisor')->update(['rol' => 'secretaria']);
    }
};