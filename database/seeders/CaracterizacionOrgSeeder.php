<?php

namespace Database\Seeders;

use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPerfil;
use App\Models\CaracterizacionSecretaria;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Semilla inicial de la jerarquía Secretaría → Dependencia, tomada del directorio
 * oficial de dependencias de la Gobernación de Casanare
 * (https://www.casanare.gov.co/directorio-de-dependencias, 44 dependencias en 10
 * secretarías/despacho/departamento, consultado 2026-09-23). Editable después desde
 * la pantalla de admin — esto es solo el punto de partida para no arrancar de cero.
 * También vincula al administrador actual del sistema a caracterizacion_perfiles.
 */
class CaracterizacionOrgSeeder extends Seeder
{
    public function run(): void
    {
        $secretarias = [
            'Despacho del Gobernador' => [
                'Secretaría Privada', 'Oficina Asesora Jurídica', 'Oficina de Defensa Judicial',
                'Oficina de Control Disciplinario Interno', 'Oficina de Control Interno de Gestión',
                'Oficina Asesora de Comunicaciones', 'Dirección de Cultura y Turismo',
            ],
            'Secretaría General' => [
                'Dirección de Tecnologías de la Información y las Comunicaciones (TIC)',
                'Dirección de Servicios Administrativos', 'Dirección de Talento Humano',
            ],
            'Secretaría de Hacienda' => [
                'Fondo de Pensiones', 'Dirección de Rentas', 'Dirección de Presupuesto',
                'Dirección de Tesorería', 'Dirección de Contabilidad', 'Dirección de Cobro Coactivo',
            ],
            'Secretaría de Gobierno, Convivencia y Seguridad Ciudadana' => [
                'Dirección de Desarrollo Comunitario', 'Dirección de Seguridad y Convivencia Ciudadana',
                'Dirección de Gestión de Riesgos y Desastres',
            ],
            'Secretaría de Infraestructura' => [
                'Dirección de Programación', 'Dirección de Construcciones',
                'Dirección de Tránsito, Transporte y Movilidad',
            ],
            'Secretaría de Integración, Desarrollo Social y Mujer' => [
                'Dirección de Inclusión y Desarrollo Social', 'Dirección de Vivienda',
            ],
            'Secretaría de Educación' => [
                'Dirección de Calidad Educativa', 'Dirección de Cobertura Educativa', 'Dirección Administrativa',
            ],
            'Secretaría de Salud' => [
                'Dirección Administrativa y Financiera', 'Dirección de Salud Pública',
                'Dirección de Seguridad Social y Garantía de la Calidad',
            ],
            'Secretaría de Desarrollo Económico, Agricultura, Ganadería y Medio Ambiente' => [
                'Dirección Empresarial', 'Dirección de Medio Ambiente',
            ],
            'Departamento Administrativo de Planeación (DAP)' => [
                'Dirección de Política Sectorial', 'Dirección de Banco de Programas y Proyectos',
            ],
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
