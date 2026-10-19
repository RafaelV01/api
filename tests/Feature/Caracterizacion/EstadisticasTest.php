<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionSeguimiento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

class EstadisticasTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    /** Dos personas; la primera va a dos reuniones y recibe un bien distinto en cada una. */
    private function escenario(): array
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $r1 = $this->crearActividad($creador, $sec, $dep);
        $r2 = $this->crearActividad($creador, $sec, $dep);
        CaracterizacionSeguimiento::create(['actividad_id' => $r1->id, 'item21_bien_servicio' => 'ZZ Semillas']);
        CaracterizacionSeguimiento::create(['actividad_id' => $r2->id, 'item21_bien_servicio' => 'ZZ Capacitación']);

        $this->agregarParticipante($r1, '800100001', ['item9_genero' => 'Mujer', 'item6_zona' => 'rural']);
        $this->agregarParticipante($r2, '800100001', ['item9_genero' => 'Mujer', 'item6_zona' => 'rural']);
        $this->agregarParticipante($r2, '800100002', ['item9_genero' => 'Hombre', 'item6_zona' => 'urbana']);
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        return [$sec, $dep];
    }

    public function test_una_persona_en_varias_reuniones_cuenta_una_sola_vez(): void
    {
        [$sec] = $this->escenario();

        $r = $this->getJson("/api/caracterizacion/estadisticas/por-variables?secretaria_id={$sec->id}")->assertOk();
        $this->assertSame(2, $r->json('total_personas'));
        $this->assertSame(3, $r->json('total_participaciones'));
        $this->assertSame(2, $r->json('total_actividades'));
        $this->assertSame(2, array_sum(array_column($r->json('desglose.genero'), 'total')));

        $dash = $this->getJson("/api/caracterizacion/estadisticas?secretaria_id={$sec->id}")->assertOk();
        $this->assertSame(2, $dash->json('total_general'));
        $this->assertSame(3, $dash->json('total_participaciones'));
    }

    public function test_el_bien_recibido_en_cada_reunion_se_cuenta_aunque_la_persona_sea_la_misma(): void
    {
        [$sec] = $this->escenario();

        $r = $this->getJson("/api/caracterizacion/estadisticas/por-variables?secretaria_id={$sec->id}")->assertOk();
        $bienes = collect($r->json('desglose.bien_servicio'))->pluck('total', 'valor');
        $this->assertSame(1, $bienes['ZZ Semillas']);
        $this->assertSame(2, $bienes['ZZ Capacitación']);
    }

    public function test_dentro_de_una_variable_se_suma_y_entre_variables_se_cruza(): void
    {
        [$sec] = $this->escenario();
        $base = "/api/caracterizacion/estadisticas/por-variables?secretaria_id={$sec->id}";

        $this->assertSame(1, $this->getJson($base.'&genero[]=Mujer')->json('total_personas'));
        $this->assertSame(2, $this->getJson($base.'&genero[]=Mujer&genero[]=Hombre')->json('total_personas'));
        $this->assertSame(1, $this->getJson($base.'&genero[]=Mujer&zona[]=rural')->json('total_personas'));
        $this->assertSame(0, $this->getJson($base.'&genero[]=Hombre&zona[]=rural')->json('total_personas'));
    }

    public function test_un_valor_hostil_no_rompe_la_consulta(): void
    {
        $this->escenario();

        $this->getJson('/api/caracterizacion/estadisticas/por-variables?'.http_build_query(['genero' => ["'; DROP TABLE usuarios;--"]]))
            ->assertOk()->assertJsonPath('total_personas', 0);
    }

    public function test_exportar_en_formato_oficial_trae_una_fila_por_participacion(): void
    {
        [$sec] = $this->escenario();

        $r = $this->get("/api/caracterizacion/estadisticas/por-variables/exportar?secretaria_id={$sec->id}")->assertOk();
        $this->assertStringStartsWith('PK', $r->getContent());
        $ruta = tempnam(sys_get_temp_dir(), 'est').'.xlsx';
        file_put_contents($ruta, $r->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');
        $this->assertStringContainsString('ESTADÍSTICAS POR VARIABLES', (string) $hoja->getCell('C6')->getValue());
        $this->assertNotEmpty($hoja->getCell('B13')->getValue());
        $this->assertNotEmpty($hoja->getCell('B15')->getValue());
        $this->assertEmpty($hoja->getCell('B16')->getValue());
        unlink($ruta);
    }

    public function test_solo_el_administrador_ve_las_estadisticas(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));

        $this->getJson('/api/caracterizacion/estadisticas/por-variables')->assertForbidden();
    }
}
