<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPerfil;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

/** Jerarquía de roles: super admin → administrador → supervisor (secretaría) → referente (dependencia) → contratista. */
class RolesYVisibilidadTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Dos secretarías; la A con dos dependencias. Una actividad en cada dependencia, de contratistas distintos. */
    private function escenario(): array
    {
        [$secA, $depA1] = $this->crearSecretariaYDependencia();
        $depA2 = CaracterizacionDependencia::create(['secretaria_id' => $secA->id, 'nombre' => 'ZZ Dep A2 '.uniqid(), 'activo' => true]);
        [$secB, $depB1] = $this->crearSecretariaYDependencia();

        $cA1 = $this->crearUsuario('contratista', $secA, $depA1);
        $cA2 = $this->crearUsuario('contratista', $secA, $depA2);
        $cB1 = $this->crearUsuario('contratista', $secB, $depB1);

        return [
            'secA' => $secA, 'secB' => $secB, 'depA1' => $depA1, 'depA2' => $depA2, 'depB1' => $depB1,
            'a1' => $this->crearActividad($cA1, $secA, $depA1), 'a2' => $this->crearActividad($cA2, $secA, $depA2), 'b1' => $this->crearActividad($cB1, $secB, $depB1),
            'cA1' => $cA1,
        ];
    }

    private function idsVisibles(): array
    {
        $r = $this->getJson('/api/caracterizacion/actividades')->assertOk();

        return collect($r->json('data') ?? $r->json())->pluck('id')->sort()->values()->all();
    }

    private function ids(...$actividades): array
    {
        return collect($actividades)->pluck('id')->sort()->values()->all();
    }

    public function test_cada_rol_ve_solo_lo_que_le_corresponde(): void
    {
        $e = $this->escenario();

        Sanctum::actingAs($e['cA1']);
        $this->assertSame($this->ids($e['a1']), $this->idsVisibles());

        Sanctum::actingAs($this->crearUsuario('referente', $e['secA'], $e['depA1']));
        $this->assertSame($this->ids($e['a1']), $this->idsVisibles()); // su dependencia

        Sanctum::actingAs($this->crearUsuario('supervisor', $e['secA'], null));
        $this->assertSame($this->ids($e['a1'], $e['a2']), $this->idsVisibles()); // toda su secretaría

        foreach (['administrador', 'super_admin'] as $global) {
            Sanctum::actingAs($this->crearUsuario($global, null, null, true));
            $visibles = $this->idsVisibles();
            foreach ([$e['a1'], $e['a2'], $e['b1']] as $a) {
                $this->assertContains($a->id, $visibles);
            }
        }
    }

    public function test_el_referente_y_el_supervisor_no_abren_actividades_ajenas(): void
    {
        $e = $this->escenario();

        Sanctum::actingAs($this->crearUsuario('referente', $e['secA'], $e['depA1']));
        $this->getJson("/api/caracterizacion/actividades/{$e['a1']->id}")->assertOk();
        $this->getJson("/api/caracterizacion/actividades/{$e['a2']->id}")->assertForbidden(); // otra dependencia
        $this->getJson("/api/caracterizacion/actividades/{$e['b1']->id}")->assertForbidden(); // otra secretaría

        Sanctum::actingAs($this->crearUsuario('supervisor', $e['secA'], null));
        $this->getJson("/api/caracterizacion/actividades/{$e['a2']->id}")->assertOk();
        $this->getJson("/api/caracterizacion/actividades/{$e['b1']->id}")->assertForbidden();
    }

    public function test_un_referente_o_supervisor_sin_dependencia_o_secretaria_no_ve_nada(): void
    {
        $e = $this->escenario();

        Sanctum::actingAs($this->crearUsuario('referente', $e['secA'], null));
        $this->assertSame([], $this->idsVisibles());
        Sanctum::actingAs($this->crearUsuario('supervisor', null, null));
        $this->assertSame([], $this->idsVisibles());
    }

    public function test_solo_un_super_admin_asigna_el_rol_de_administrador_si_ya_existe_uno(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $destino = $this->crearUsuario('contratista', $sec, $dep);
        $cuerpo = ['caracterizacion_rol' => 'administrador'];

        // Mientras no exista ningún super admin, el administrador del sistema puede
        CaracterizacionPerfil::where('rol', 'super_admin')->update(['activo' => false, 'rol' => 'zz_temporal']);
        Sanctum::actingAs($this->crearUsuario('administrador', null, null, true));
        $this->putJson("/api/usuarios/{$destino->id}", $cuerpo)->assertOk();
        $this->assertSame('administrador', $destino->fresh()->caracterizacionPerfil->rol);

        // Con un super admin ya creado, un administrador común ya no puede promover a otro
        $this->crearUsuario('super_admin', null, null, true);
        $otro = $this->crearUsuario('contratista', $sec, $dep);
        $this->putJson("/api/usuarios/{$otro->id}", $cuerpo)->assertForbidden();
        $this->assertSame('contratista', $otro->fresh()->caracterizacionPerfil->rol);

        // El super admin sí
        Sanctum::actingAs($this->crearUsuario('super_admin', null, null, true));
        $this->putJson("/api/usuarios/{$otro->id}", $cuerpo)->assertOk();
        $this->assertSame('administrador', $otro->fresh()->caracterizacionPerfil->rol);
    }

    public function test_los_roles_nuevos_son_validos_al_asignar_y_uno_inventado_no(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u = $this->crearUsuario('contratista', $sec, $dep);
        Sanctum::actingAs($this->crearUsuario('administrador', null, null, true));

        foreach (['supervisor', 'referente'] as $rol) {
            $this->putJson("/api/usuarios/{$u->id}", ['caracterizacion_rol' => $rol, 'caracterizacion_secretaria_id' => $sec->id, 'caracterizacion_dependencia_id' => $dep->id])->assertOk();
            $this->assertSame($rol, $u->fresh()->caracterizacionPerfil->rol);
        }
        $this->putJson("/api/usuarios/{$u->id}", ['caracterizacion_rol' => 'secretaria'])->assertStatus(422); // el nombre anterior ya no existe
        $this->putJson("/api/usuarios/{$u->id}", ['caracterizacion_rol' => 'dios'])->assertStatus(422);
    }
}
