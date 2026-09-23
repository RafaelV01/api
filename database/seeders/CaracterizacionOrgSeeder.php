<?php

namespace Database\Seeders;

use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPerfil;
use App\Models\CaracterizacionSecretaria;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Semilla inicial de la jerarquía Secretaría → Dependencia, tomada de la lista que
 * ya existe en el frontend (src/data/Dependencias.jsx), corrigiendo los typos
 * ("SECREATRÍA" → "SECRETARÍA"). Editable después desde la pantalla de admin —
 * esto es solo el punto de partida para no arrancar de cero.
 * También vincula al administrador actual del sistema a caracterizacion_perfiles.
 */
class CaracterizacionOrgSeeder extends Seeder
{
    public function run(): void
    {
        $secretarias = [
            'DAP' => ['Despacho', 'Política Sectorial', 'Banco de Proyectos'],
            'SECRETARÍA DE SALUD' => [],
            'SECRETARÍA DE HACIENDA' => ['Tesorería', 'Presupuesto', 'Contabilidad'],
            'SECRETARÍA DE EDUCACIÓN' => [],
            'SECRETARÍA DE GOBIERNO, CONVIVENCIA Y SEGURIDAD CIUDADANA' => ['Asuntos Municipales', 'Seguridad y Convivencia'],
            'SECRETARÍA PRIVADA' => [],
            'SECRETARÍA GENERAL' => [],
            'SECRETARÍA DE INFRAESTRUCTURA' => [],
            'SECRETARÍA DE DESARROLLO ECONÓMICO, AGRICULTURA, GANADERÍA Y MEDIO AMBIENTE' => [],
        ];

        foreach ($secretarias as $nombreSecretaria => $oficinas) {
            $secretaria = CaracterizacionSecretaria::updateOrCreate(
                ['nombre' => $nombreSecretaria],
                ['activo' => true]
            );

            // Si no tiene oficinas específicas, se crea una dependencia "Despacho" por
            // defecto para que siempre haya al menos una dependencia donde asignar contratistas.
            $dependencias = $oficinas ?: ['Despacho'];

            foreach ($dependencias as $nombreDependencia) {
                CaracterizacionDependencia::updateOrCreate(
                    ['secretaria_id' => $secretaria->id, 'nombre' => $nombreDependencia],
                    ['activo' => true]
                );
            }
        }

        // Vincula al administrador actual del sistema (rol_id = 1) como administrador
        // también dentro de Caracterización de Ciudadanía, sin tocar `usuarios`/`roles`.
        User::where('rol_id', 1)->each(function (User $admin) {
            CaracterizacionPerfil::updateOrCreate(
                ['usuario_id' => $admin->id],
                ['rol' => CaracterizacionPerfil::ROL_ADMINISTRADOR, 'activo' => true]
            );
        });
    }
}
