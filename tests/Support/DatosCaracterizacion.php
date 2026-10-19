<?php

namespace Tests\Support;

use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAsistente;
use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPersona;
use App\Models\CaracterizacionSecretaria;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea datos de prueba de Caracterización de Ciudadanía. Las pruebas corren dentro de una transacción que se
 * deshace al terminar (DatabaseTransactions), así que no dejan nada en la base.
 */
trait DatosCaracterizacion
{
    protected function crearSecretariaYDependencia(): array
    {
        $sec = CaracterizacionSecretaria::create(['nombre' => 'ZZ Secretaría '.Str::random(6), 'activo' => true]);
        $dep = CaracterizacionDependencia::create(['secretaria_id' => $sec->id, 'nombre' => 'ZZ Dependencia '.Str::random(6), 'activo' => true]);

        return [$sec, $dep];
    }

    /** Usuario con (opcionalmente) rol de Caracterización. `$admin` lo hace administrador del sistema y de Caracterización. */
    protected function crearUsuario(?string $rolCaracterizacion = null, $sec = null, $dep = null, bool $admin = false): User
    {
        $id = DB::table('usuarios')->insertGetId([
            'nombre_completo' => 'ZZ Usuario '.Str::random(6), 'cargo' => 'Prueba', 'dependencia' => 'ZZ',
            'email' => 'zz'.Str::random(8).'@example.test', 'telefono' => '3001234567',
            'password' => bcrypt('Clave12345'), 'rol_id' => $admin ? 1 : 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($rolCaracterizacion) {
            DB::table('caracterizacion_perfiles')->insert([
                'usuario_id' => $id, 'rol' => $rolCaracterizacion, 'secretaria_id' => $sec?->id, 'dependencia_id' => $dep?->id,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return User::find($id);
    }

    protected function crearActividad(User $creador, $sec, $dep, array $atributos = []): CaracterizacionActividad
    {
        return CaracterizacionActividad::create($atributos + [
            'codigo' => Str::upper(Str::random(8)), 'slug_acceso' => 'zz-'.Str::random(12), 'tema' => 'ZZ Tema '.Str::random(5),
            'fecha' => now()->subDays(3)->toDateString(), 'hora' => '10:00:00', 'quien_dirige_expone' => 'Persona Prueba',
            'firma_expositor_base64' => 'data:image/jpeg;base64,eA==', 'firma_expositor_hash' => str_repeat('a', 64),
            'municipio' => 'YOPAL', 'departamento' => 'Casanare', 'tipo_evento' => 'capacitacion',
            'creador_id' => $creador->id, 'dependencia_id' => $dep->id, 'secretaria_id' => $sec->id,
            'estado' => 'activa', 'estado_aprobacion' => 'aprobada',
        ]);
    }

    /** Una participación de la persona con ese documento en la actividad (crea o reutiliza su ficha). */
    protected function agregarParticipante(CaracterizacionActividad $actividad, string $documento, array $atributos = []): CaracterizacionAsistente
    {
        $datos = $atributos + [
            'item1_nombre' => 'ZZ Persona '.$documento, 'item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento,
            'item4_cargo_barrio_vereda' => 'Presidente', 'item5_municipio' => 'YOPAL', 'item6_zona' => 'urbana',
            'item7_ubicacion_tipo' => 'Barrio', 'item8_contacto_tipo' => 'Celular', 'item8_contacto_valor' => '3001234567',
            'item9_genero' => 'Mujer', 'item15_edad' => 30, 'item16_tamano_grupo_familiar' => 3,
            'firma_ciudadano_base64' => 'data:image/jpeg;base64,eA==', 'firma_ciudadano_hash' => str_repeat('b', 64),
            'ip_origen' => '127.0.0.1', 'user_agent' => 'pruebas',
        ];
        $persona = CaracterizacionPersona::firstOrCreate(
            ['persona_clave' => CaracterizacionPersona::clave($datos['item2_tipo_documento'], $documento)],
            [
                'tipo_documento' => $datos['item2_tipo_documento'], 'numero_documento' => $documento, 'nombre' => $datos['item1_nombre'],
                'municipio' => $datos['item5_municipio'], 'zona' => $datos['item6_zona'], 'genero' => $datos['item9_genero'], 'edad' => $datos['item15_edad'],
            ]
        );
        $persona->increment('participaciones');

        return $actividad->asistentes()->create($datos + ['persona_id' => $persona->id]);
    }
}
