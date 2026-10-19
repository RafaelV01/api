<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionSeguimientoAsistente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

class SeguimientoYUsuariosTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    public function test_el_seguimiento_en_lote_se_aplica_a_cada_ciudadano_con_su_codigo_dane(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $actividad = $this->crearActividad($creador, $sec, $dep);
        $yopal = $this->agregarParticipante($actividad, '700100001', ['item5_municipio' => 'YOPAL']);
        $orocue = $this->agregarParticipante($actividad, '700100002', ['item5_municipio' => 'OROCUE']);
        Sanctum::actingAs($creador);

        $this->putJson("/api/caracterizacion/actividades/{$actividad->id}/seguimiento-lote", [
            'asistentes' => [$yopal->id, $orocue->id], 'item21_bien_servicio' => 'ZZ Lote', 'item20_codigo_dane' => '00000',
        ])->assertOk()->assertJsonPath('actualizados', 2);

        $this->assertSame('85001', CaracterizacionSeguimientoAsistente::where('asistente_id', $yopal->id)->value('item20_codigo_dane'));
        $this->assertSame('85230', CaracterizacionSeguimientoAsistente::where('asistente_id', $orocue->id)->value('item20_codigo_dane'));

        $this->putJson("/api/caracterizacion/actividades/{$actividad->id}/seguimiento-lote", ['asistentes' => [$yopal->id, $orocue->id], 'quitar' => true])->assertOk();
        $this->assertSame(0, CaracterizacionSeguimientoAsistente::whereIn('asistente_id', [$yopal->id, $orocue->id])->count());
    }

    public function test_el_lote_rechaza_ciudadanos_de_otra_actividad(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $propia = $this->crearActividad($creador, $sec, $dep);
        $ajena = $this->crearActividad($creador, $sec, $dep);
        $mio = $this->agregarParticipante($propia, '700100003');
        $otro = $this->agregarParticipante($ajena, '700100004');
        Sanctum::actingAs($creador);

        $this->putJson("/api/caracterizacion/actividades/{$propia->id}/seguimiento-lote", ['asistentes' => [$mio->id, $otro->id], 'item21_bien_servicio' => 'ZZ'])->assertStatus(422);
    }

    public function test_otro_contratista_no_puede_editar_el_seguimiento_de_una_actividad_ajena(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);
        $p = $this->agregarParticipante($actividad, '700100005');
        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));

        $this->putJson("/api/caracterizacion/actividades/{$actividad->id}/seguimiento-lote", ['asistentes' => [$p->id], 'item21_bien_servicio' => 'ZZ'])->assertForbidden();
    }

    public function test_usuarios_disponibles_se_acotan_por_secretaria_y_se_buscan_en_el_servidor(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        [$otraSec, $otraDep] = $this->crearSecretariaYDependencia();
        $uno = $this->crearUsuario('contratista', $sec, $dep);
        $this->crearUsuario('contratista', $sec, $dep);
        $this->crearUsuario('contratista', $otraSec, $otraDep);
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $this->assertCount(3, $this->getJson("/api/caracterizacion/usuarios-disponibles?secretaria_id={$sec->id}")->json());
        $this->assertCount(1, $this->getJson("/api/caracterizacion/usuarios-disponibles?secretaria_id={$otraSec->id}")->json());
        $this->assertCount(1, $this->getJson('/api/caracterizacion/usuarios-disponibles?'.http_build_query(['secretaria_id' => $sec->id, 'q' => $uno->email]))->json());
        $this->assertCount(1, $this->getJson("/api/caracterizacion/usuarios-disponibles?secretaria_id={$sec->id}&limit=1")->json());
        // Un comodín del buscador se trata como texto, no como «todo»
        $this->assertCount(0, $this->getJson('/api/caracterizacion/usuarios-disponibles?'.http_build_query(['secretaria_id' => $sec->id, 'q' => '%']))->json());
    }

    public function test_sin_filtros_el_buscador_de_usuarios_funciona_desde_el_inicio(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $usuario = $this->crearUsuario('contratista', $sec, $dep);
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $r = $this->getJson('/api/caracterizacion/usuarios-disponibles?'.http_build_query(['q' => $usuario->email, 'limit' => 30]))->assertOk();
        $this->assertCount(1, $r->json());
    }

    public function test_asignar_dependencia_a_varios_usuarios_a_la_vez(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u1 = $this->crearUsuario();
        $u2 = $this->crearUsuario('contratista');
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $this->postJson('/api/usuarios/asignar-dependencia', [
            'usuario_ids' => [$u1->id, $u2->id], 'caracterizacion_secretaria_id' => $sec->id, 'caracterizacion_dependencia_id' => $dep->id,
        ])->assertOk()->assertJsonPath('actualizados', 2);

        $this->assertStringContainsString($dep->nombre, $u1->fresh()->dependencia);
        $this->assertSame($dep->id, $u2->fresh()->caracterizacionPerfil->dependencia_id);
        $this->assertNull($u1->fresh()->caracterizacionPerfil); // sin rol de Caracterización no se le crea perfil

        $this->postJson('/api/usuarios/asignar-dependencia', [
            'usuario_ids' => [$u1->id], 'caracterizacion_secretaria_id' => $sec->id + 100000, 'caracterizacion_dependencia_id' => $dep->id,
        ])->assertStatus(422);
    }

    public function test_la_lista_de_usuarios_se_pagina_y_busca(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $buscado = $this->crearUsuario();
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $pagina = $this->getJson('/api/usuarios?page=1&per_page=5')->assertOk();
        $this->assertCount(5, $pagina->json('data'));
        $this->assertGreaterThanOrEqual(5, $pagina->json('total'));
        $this->assertSame(1, $this->getJson('/api/usuarios?'.http_build_query(['page' => 1, 'q' => $buscado->email]))->json('total'));
        $this->assertIsArray($this->getJson('/api/usuarios')->json()); // sin paginar: lista completa (clientes antiguos)
    }
}
