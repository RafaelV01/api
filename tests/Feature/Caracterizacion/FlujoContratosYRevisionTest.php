<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionColaborador;
use App\Models\CaracterizacionContrato;
use App\Models\CaracterizacionContratoBloque;
use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionNotificacion;
use App\Models\CaracterizacionSeguimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

/** Contratos con bloques, reunión que copia el contrato, revisión en cadena (referente → supervisor) y filas del Excel por contratista. */
class FlujoContratosYRevisionTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function bloque(array $extra = []): array
    {
        return $extra + ['nombre' => 'Actividad', 'item21_bien_servicio' => 'Capacitación', 'item26_programa' => 'Programa X', 'item26_meta_producto' => 'Meta A', 'item24_gestion_inversion' => 'Gestión'];
    }

    private function contratoConBloques(User $u, array $bloques, string $numero = 'CPS-001-2026'): CaracterizacionContrato
    {
        $c = CaracterizacionContrato::create(['usuario_id' => $u->id, 'numero_contrato' => $numero, 'fecha_inicio' => now()->subMonth()->toDateString(), 'fecha_fin' => now()->addMonths(6)->toDateString(), 'activo' => true]);
        foreach (array_values($bloques) as $i => $b) {
            CaracterizacionContratoBloque::create(['contrato_id' => $c->id, 'orden' => $i + 1] + $b);
        }

        return $c;
    }

    private function cuerpoReunion(array $extra = []): array
    {
        return $extra + ['tema' => 'Reunión de prueba', 'fecha' => now()->toDateString(), 'hora' => '10:00', 'quien_dirige_expone' => 'Persona Prueba', 'municipio' => 'YOPAL', 'tipo_evento' => 'capacitacion'];
    }

    /** Secretaría con dos dependencias, supervisor, un referente por dependencia y un contratista en la primera. */
    private function organizacion(): array
    {
        [$sec, $dep1] = $this->crearSecretariaYDependencia();
        $dep2 = CaracterizacionDependencia::create(['secretaria_id' => $sec->id, 'nombre' => 'ZZ Dep2 '.uniqid(), 'activo' => true]);

        return [
            'sec' => $sec, 'dep1' => $dep1, 'dep2' => $dep2,
            'supervisor' => $this->crearUsuario('supervisor', $sec, null),
            'ref1' => $this->crearUsuario('referente', $sec, $dep1), 'ref2' => $this->crearUsuario('referente', $sec, $dep2),
            'contratista' => $this->crearUsuario('contratista', $sec, $dep1),
        ];
    }

    // ── Reunión que copia el contrato ──

    public function test_la_reunion_nace_en_borrador_y_copia_el_bloque_del_contrato(): void
    {
        $o = $this->organizacion();
        $this->contratoConBloques($o['contratista'], [$this->bloque()]);
        Sanctum::actingAs($o['contratista']);

        $r = $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion())->assertCreated();
        $this->assertSame('borrador', $r->json('estado_aprobacion'));
        $seg = CaracterizacionSeguimiento::where('actividad_id', $r->json('id'))->first();
        $this->assertSame('Meta A', $seg->item26_meta_producto);
        $this->assertSame($o['contratista']->id, $seg->usuario_id);
        $this->assertSame('85001', $seg->item20_codigo_dane); // sale del municipio (Yopal)
    }

    public function test_con_varias_actividades_en_el_contrato_hay_que_elegir_una(): void
    {
        $o = $this->organizacion();
        $c = $this->contratoConBloques($o['contratista'], [$this->bloque(['item26_meta_producto' => 'Meta A']), $this->bloque(['item26_meta_producto' => 'Meta B'])]);
        Sanctum::actingAs($o['contratista']);

        $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion())->assertStatus(422)->assertJsonValidationErrors('contrato_bloque_id');
        $segundo = $c->bloques()->where('orden', 2)->first();
        $r = $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion(['contrato_bloque_id' => $segundo->id]))->assertCreated();
        $this->assertSame('Meta B', CaracterizacionSeguimiento::where('actividad_id', $r->json('id'))->value('item26_meta_producto'));

        // Un bloque ajeno no vale
        $otro = $this->crearUsuario('contratista', $o['sec'], $o['dep1']);
        $ajeno = $this->contratoConBloques($otro, [$this->bloque()], 'CPS-999-2026')->bloques()->first();
        $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion(['contrato_bloque_id' => $ajeno->id]))->assertStatus(422);
    }

    public function test_un_contrato_nuevo_no_cambia_las_reuniones_anteriores(): void
    {
        $o = $this->organizacion();
        $this->contratoConBloques($o['contratista'], [$this->bloque(['item26_meta_producto' => 'Meta del contrato 1'])], 'CPS-001-2026');
        Sanctum::actingAs($o['contratista']);
        $primera = $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion())->assertCreated()->json('id');

        // El supervisor registra el contrato siguiente (otro número, otra meta)
        Sanctum::actingAs($o['supervisor']);
        $this->putJson("/api/caracterizacion/contratistas/{$o['contratista']->id}/contrato", [
            'numero_contrato' => 'CPS-077-2026', 'fecha_inicio' => now()->subDay()->toDateString(), 'fecha_fin' => now()->addMonths(8)->toDateString(),
            'bloques' => [$this->bloque(['item26_meta_producto' => 'Meta del contrato 2'])],
        ])->assertOk();
        $this->assertSame(2, CaracterizacionContrato::where('usuario_id', $o['contratista']->id)->count());

        Sanctum::actingAs($o['contratista']);
        $segunda = $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion())->assertCreated()->json('id');
        $this->assertSame('Meta del contrato 1', CaracterizacionSeguimiento::where('actividad_id', $primera)->value('item26_meta_producto'));
        $this->assertSame('Meta del contrato 2', CaracterizacionSeguimiento::where('actividad_id', $segunda)->value('item26_meta_producto'));
    }

    public function test_el_firmante_de_la_reunion_se_guarda_y_una_firma_falsa_se_rechaza(): void
    {
        $o = $this->organizacion();
        Sanctum::actingAs($o['contratista']);

        $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion(['firma_expositor_base64' => 'data:text/html;base64,'.base64_encode('<b>x</b>'), 'firma_expositor_hash' => str_repeat('a', 64)]))
            ->assertStatus(422)->assertJsonValidationErrors('firma_expositor_base64');
        $id = $this->postJson('/api/caracterizacion/actividades', $this->cuerpoReunion(['firma_expositor_base64' => $this->firmaDePrueba(), 'firma_expositor_hash' => str_repeat('a', 64)]))->assertCreated()->json('id');
        $this->assertStringStartsWith('data:image/', CaracterizacionActividad::find($id)->firma_expositor_base64);
    }

    // ── Módulo de contratistas ──

    public function test_cada_revisor_ve_y_edita_solo_a_los_contratistas_de_su_alcance(): void
    {
        $o = $this->organizacion();
        $de2 = $this->crearUsuario('contratista', $o['sec'], $o['dep2']);
        [$otraSec, $otraDep] = $this->crearSecretariaYDependencia();
        $deOtraSec = $this->crearUsuario('contratista', $otraSec, $otraDep);
        $ids = fn () => collect($this->getJson('/api/caracterizacion/contratistas?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        Sanctum::actingAs($o['referente'] ?? $o['ref1']);
        $this->assertContains($o['contratista']->id, $ids());
        $this->assertNotContains($de2->id, $ids());
        $this->putJson("/api/caracterizacion/contratistas/{$de2->id}", ['telefono' => '3000000000'])->assertNotFound();

        Sanctum::actingAs($o['supervisor']);
        $this->assertEqualsCanonicalizing([$o['contratista']->id, $de2->id], array_values(array_intersect($ids(), [$o['contratista']->id, $de2->id, $deOtraSec->id])));
        $this->assertNotContains($deOtraSec->id, $ids());

        Sanctum::actingAs($this->crearUsuario('administrador', null, null, true));
        $this->assertContains($deOtraSec->id, $ids());

        Sanctum::actingAs($o['contratista']); // un contratista no entra al módulo
        $this->getJson('/api/caracterizacion/contratistas')->assertForbidden();
    }

    public function test_el_supervisor_edita_credenciales_y_la_contrasena_nueva_cierra_las_sesiones(): void
    {
        $o = $this->organizacion();
        $u = $o['contratista'];
        $u->createToken('vieja');
        Sanctum::actingAs($o['supervisor']);

        $this->putJson("/api/caracterizacion/contratistas/{$u->id}", ['nombre_completo' => 'Nombre Corregido', 'email' => 'nuevo.correo@example.test', 'telefono' => '3109998877', 'password' => 'ClaveNueva2026'])->assertOk();
        $u->refresh();
        $this->assertSame('Nombre Corregido', $u->nombre_completo);
        $this->assertSame('nuevo.correo@example.test', $u->email);
        $this->assertTrue(Hash::check('ClaveNueva2026', $u->password));
        $this->assertSame(0, $u->tokens()->count());

        $this->putJson("/api/caracterizacion/contratistas/{$u->id}", ['email' => 'no-es-correo'])->assertStatus(422);
        $this->putJson("/api/caracterizacion/contratistas/{$u->id}", ['password' => 'corta'])->assertStatus(422);
        $otro = $this->crearUsuario('contratista', $o['sec'], $o['dep1']);
        $this->putJson("/api/caracterizacion/contratistas/{$u->id}", ['email' => $otro->email])->assertStatus(422); // ya está en uso
    }

    public function test_el_contrato_valida_fechas_y_maximo_tres_actividades(): void
    {
        $o = $this->organizacion();
        Sanctum::actingAs($o['ref1']);
        $url = "/api/caracterizacion/contratistas/{$o['contratista']->id}/contrato";
        $base = ['numero_contrato' => 'CPS-010-2026', 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-12-31'];

        $this->putJson($url, $base + ['bloques' => [$this->bloque(), $this->bloque(), $this->bloque(), $this->bloque()]])->assertStatus(422)->assertJsonValidationErrors('bloques');
        $this->putJson($url, $base + ['bloques' => []])->assertStatus(422);
        $this->putJson($url, ['fecha_fin' => '2026-01-01'] + $base + ['bloques' => [$this->bloque()]])->assertStatus(422)->assertJsonValidationErrors('fecha_fin');
        $this->putJson($url, $base + ['bloques' => [$this->bloque(['item24_gestion_inversion' => 'Otra cosa'])]])->assertStatus(422);

        $this->putJson($url, $base + ['bloques' => [$this->bloque(), $this->bloque(['item26_meta_producto' => 'Meta B']), $this->bloque(['item26_meta_producto' => 'Meta C'])]])->assertOk();
        $this->getJson("/api/caracterizacion/contratistas/{$o['contratista']->id}")->assertOk()->assertJsonCount(3, 'contrato.bloques');
        // Al quitar una actividad del mismo contrato, queda en dos
        $this->putJson($url, $base + ['bloques' => [$this->bloque(), $this->bloque(['item26_meta_producto' => 'Meta B'])]])->assertOk();
        $this->getJson("/api/caracterizacion/contratistas/{$o['contratista']->id}")->assertJsonCount(2, 'contrato.bloques');
        $this->assertSame(1, CaracterizacionContrato::where('usuario_id', $o['contratista']->id)->count());
    }

    // ── Revisión en cadena ──

    private function reunionConAsistente(array $o): CaracterizacionActividad
    {
        $a = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);
        $this->agregarParticipante($a, '600'.random_int(100000, 999999));

        return $a;
    }

    public function test_la_reunion_pasa_por_referente_y_supervisor_y_queda_aprobada(): void
    {
        $o = $this->organizacion();
        $a = $this->reunionConAsistente($o);

        Sanctum::actingAs($o['contratista']);
        $this->postJson("/api/caracterizacion/actividades/{$a->id}/enviar-revision")->assertOk();
        $this->assertSame('pendiente', $a->fresh()->estado_aprobacion);
        $this->assertSame(1, CaracterizacionNotificacion::where('usuario_id', $o['ref1']->id)->count()); // avisa al referente
        $this->postJson("/api/caracterizacion/actividades/{$a->id}/enviar-revision")->assertStatus(422); // ya enviada

        // No le corresponde al referente de otra dependencia, ni al supervisor todavía
        Sanctum::actingAs($o['ref2']);
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'aprobar'])->assertForbidden();
        Sanctum::actingAs($o['supervisor']);
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'aprobar'])->assertForbidden();

        Sanctum::actingAs($o['ref1']);
        $this->assertSame([$a->id], collect($this->getJson('/api/caracterizacion/revision/bandeja')->assertOk()->json('data'))->pluck('id')->all());
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'aprobar'])->assertOk();
        $this->assertSame('revision_supervisor', $a->fresh()->estado_aprobacion);
        $this->assertSame($o['ref1']->id, $a->fresh()->referente_aprobo_por);
        $this->assertSame([], $this->getJson('/api/caracterizacion/revision/bandeja')->json('data')); // ya no es suya
        $this->assertSame(1, CaracterizacionNotificacion::where('usuario_id', $o['supervisor']->id)->count());

        Sanctum::actingAs($o['supervisor']);
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'aprobar'])->assertOk();
        $this->assertSame('aprobada', $a->fresh()->estado_aprobacion);
        $this->assertSame($o['supervisor']->id, $a->fresh()->aprobado_por);
        $this->assertSame(1, CaracterizacionNotificacion::where('usuario_id', $o['contratista']->id)->where('tipo', 'aprobada')->count());
    }

    public function test_al_devolver_se_exige_el_motivo_y_el_contratista_puede_corregir_y_reenviar(): void
    {
        $o = $this->organizacion();
        $a = $this->reunionConAsistente($o);
        $a->update(['estado_aprobacion' => 'pendiente', 'enviada_en' => now()]);

        Sanctum::actingAs($o['ref1']);
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'devolver'])->assertStatus(422)->assertJsonValidationErrors('observacion');
        $this->postJson("/api/caracterizacion/revision/{$a->id}/resolver", ['accion' => 'devolver', 'observacion' => 'Falta la descripción del beneficio.'])->assertOk();
        $this->assertSame('rechazada', $a->fresh()->estado_aprobacion);
        $this->assertSame('Falta la descripción del beneficio.', $a->fresh()->observacion_aprobacion);
        $this->assertStringContainsString('Falta la descripción', CaracterizacionNotificacion::where('usuario_id', $o['contratista']->id)->latest('id')->value('mensaje') ?? '');

        Sanctum::actingAs($o['contratista']);
        $this->postJson("/api/caracterizacion/actividades/{$a->id}/enviar-revision")->assertOk();
        $this->assertSame('pendiente', $a->fresh()->estado_aprobacion);
        $this->assertNull($a->fresh()->observacion_aprobacion);
    }

    public function test_sin_asistentes_no_se_envia_y_sin_referente_pasa_directo_al_supervisor(): void
    {
        $o = $this->organizacion();
        $vacia = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);
        Sanctum::actingAs($o['contratista']);
        $this->postJson("/api/caracterizacion/actividades/{$vacia->id}/enviar-revision")->assertStatus(422);

        // Otra dependencia sin referente
        $dep3 = CaracterizacionDependencia::create(['secretaria_id' => $o['sec']->id, 'nombre' => 'ZZ Dep3 '.uniqid(), 'activo' => true]);
        $c3 = $this->crearUsuario('contratista', $o['sec'], $dep3);
        $a = $this->crearActividad($c3, $o['sec'], $dep3, ['estado_aprobacion' => 'borrador']);
        $this->agregarParticipante($a, '600'.random_int(100000, 999999));
        Sanctum::actingAs($c3);
        $this->postJson("/api/caracterizacion/actividades/{$a->id}/enviar-revision")->assertOk();
        $this->assertSame('revision_supervisor', $a->fresh()->estado_aprobacion);
    }

    public function test_enviar_todos_los_borradores_y_nadie_mas_puede_enviar_una_reunion_ajena(): void
    {
        $o = $this->organizacion();
        $lista = $this->reunionConAsistente($o);
        $vacia = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);

        Sanctum::actingAs($o['contratista']);
        $r = $this->postJson('/api/caracterizacion/actividades/enviar-borradores')->assertOk();
        $this->assertSame(1, $r->json('enviadas'));
        $this->assertSame(1, $r->json('sin_asistentes'));
        $this->assertSame('pendiente', $lista->fresh()->estado_aprobacion);
        $this->assertSame('borrador', $vacia->fresh()->estado_aprobacion);

        $otro = $this->crearUsuario('contratista', $o['sec'], $o['dep1']);
        Sanctum::actingAs($otro);
        $this->postJson("/api/caracterizacion/actividades/{$vacia->id}/enviar-revision")->assertForbidden();
    }

    public function test_las_estadisticas_solo_cuentan_lo_aprobado_por_toda_la_cadena(): void
    {
        $o = $this->organizacion();
        $a = $this->reunionConAsistente($o);
        Sanctum::actingAs($this->crearUsuario('administrador', $o['sec'], null, true));
        $total = fn () => $this->getJson("/api/caracterizacion/estadisticas?secretaria_id={$o['sec']->id}")->assertOk()->json('total_general');

        foreach (['borrador', 'pendiente', 'revision_supervisor', 'rechazada'] as $estado) {
            $a->update(['estado_aprobacion' => $estado]);
            $this->assertSame(0, $total(), "no debe contar en estado {$estado}");
        }
        $a->update(['estado_aprobacion' => 'aprobada']);
        $this->assertSame(1, $total());
    }

    // ── Bloqueo de edición mientras se revisa ──

    public function test_el_contratista_no_edita_su_reunion_mientras_esta_en_revision_ni_aprobada_pero_si_devuelta(): void
    {
        $o = $this->organizacion();
        $a = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);
        $url = "/api/caracterizacion/actividades/{$a->id}/parte2";
        $cuerpo = ['item22_descripcion_beneficio' => 'Apoyo'];

        Sanctum::actingAs($o['contratista']);
        $this->putJson($url, $cuerpo)->assertOk(); // borrador
        foreach (['pendiente', 'revision_supervisor', 'aprobada'] as $estado) {
            $a->update(['estado_aprobacion' => $estado]);
            $this->putJson($url, $cuerpo)->assertStatus(423);
            $this->putJson("/api/caracterizacion/actividades/{$a->id}/seguimiento-lote", ['asistentes' => [1], 'quitar' => true])->assertStatus(423);
        }
        $a->update(['estado_aprobacion' => 'rechazada']);
        $this->putJson($url, $cuerpo)->assertOk(); // devuelta: puede corregir

        // Un revisor sí puede ajustar mientras la revisa
        $a->update(['estado_aprobacion' => 'pendiente']);
        Sanctum::actingAs($o['ref1']);
        $this->putJson($url, $cuerpo)->assertOk();
    }

    public function test_el_filtro_de_estado_acepta_varios_valores_y_el_resumen_del_admin_cuenta_las_dos_etapas(): void
    {
        $o = $this->organizacion();
        $creador = $o['contratista'];
        $this->crearActividad($creador, $o['sec'], $o['dep1'], ['estado_aprobacion' => 'pendiente']);
        $this->crearActividad($creador, $o['sec'], $o['dep1'], ['estado_aprobacion' => 'revision_supervisor']);
        $this->crearActividad($creador, $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);

        Sanctum::actingAs($this->crearUsuario('administrador', $o['sec'], null, true));
        $lista = $this->getJson("/api/caracterizacion/actividades?secretaria_id={$o['sec']->id}&estado_aprobacion=pendiente,revision_supervisor")->assertOk()->json();
        $this->assertCount(2, $lista);

        $resumen = $this->getJson('/api/caracterizacion/aprobaciones/resumen')->assertOk();
        $this->assertSame(2, (int) collect($resumen->json('por_secretaria'))->firstWhere('id', $o['sec']->id)['pendientes']);
    }

    public function test_mi_contrato_informa_cuantos_dias_le_quedan(): void
    {
        $o = $this->organizacion();
        $c = CaracterizacionContrato::create(['usuario_id' => $o['contratista']->id, 'numero_contrato' => 'CPS-1', 'fecha_inicio' => now()->subMonth()->toDateString(), 'fecha_fin' => now()->addDays(9)->toDateString(), 'activo' => true]);
        CaracterizacionContratoBloque::create(['contrato_id' => $c->id, 'orden' => 1] + $this->bloque());

        Sanctum::actingAs($o['contratista']);
        $this->getJson('/api/caracterizacion/mi-contrato')->assertOk()->assertJsonPath('contrato.dias_restantes', 9)->assertJsonPath('contrato.estado', 'vigente');
    }

    // ── Varios contratistas en una reunión ──

    private function reunionConDosContratistas(string $metaCreador, string $metaColaborador): array
    {
        $o = $this->organizacion();
        $colab = $this->crearUsuario('contratista', $o['sec'], $o['dep1']);
        $a = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1'], ['estado_aprobacion' => 'borrador']);
        CaracterizacionColaborador::create(['actividad_id' => $a->id, 'usuario_id' => $colab->id, 'invitado_por' => $o['contratista']->id, 'estado' => CaracterizacionColaborador::ESTADO_ACEPTADO, 'created_at' => now()]);
        CaracterizacionSeguimiento::create(['actividad_id' => $a->id, 'usuario_id' => $o['contratista']->id, 'item26_meta_producto' => $metaCreador, 'item21_bien_servicio' => 'Servicio 1']);
        CaracterizacionSeguimiento::create(['actividad_id' => $a->id, 'usuario_id' => $colab->id, 'item26_meta_producto' => $metaColaborador, 'item21_bien_servicio' => 'Servicio 2']);
        foreach (['700000001', '700000002'] as $doc) {
            $this->agregarParticipante($a, $doc);
        }

        return [$a, $o['contratista'], $colab];
    }

    private function filasDelExcel(CaracterizacionActividad $a, User $quien): array
    {
        Sanctum::actingAs($quien);
        $r = $this->get("/api/caracterizacion/actividades/{$a->id}/excel")->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'fx').'.xlsx';
        file_put_contents($ruta, $r->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');
        unlink($ruta);
        $filas = [];
        for ($f = 13; $f < 30; $f++) {
            if ($hoja->getCell("C{$f}")->getValue() === null && $hoja->getCell("D{$f}")->getValue() === null) {
                break;
            }
            $filas[] = ['doc' => (string) $hoja->getCell("D{$f}")->getValue(), 'meta' => $hoja->getCell("AS{$f}")->getValue(), 'total' => (int) $hoja->getCell("AZ{$f}")->getValue()];
        }

        return $filas;
    }

    public function test_cada_persona_sale_una_vez_por_cada_meta_distinta_de_los_contratistas_de_la_reunion(): void
    {
        [$a, $creador] = $this->reunionConDosContratistas('Meta Uno', 'Meta Dos');
        $filas = $this->filasDelExcel($a, $creador);

        $this->assertCount(4, $filas); // 2 personas × 2 metas distintas
        $this->assertEqualsCanonicalizing(['Meta Uno', 'Meta Dos', 'Meta Uno', 'Meta Dos'], array_column($filas, 'meta'));
        $this->assertSame(2, count(array_unique(array_column($filas, 'doc'))));
        $this->assertSame([2, 2, 2, 2], array_column($filas, 'total')); // beneficiarios = asistentes, no filas
    }

    public function test_si_la_cedula_y_la_meta_coinciden_la_persona_sale_una_sola_vez(): void
    {
        [$a, $creador] = $this->reunionConDosContratistas('Misma Meta', ' misma meta ');
        $filas = $this->filasDelExcel($a, $creador);

        $this->assertCount(2, $filas); // una por persona: la meta repetida no duplica
        $this->assertSame(['Misma Meta', 'Misma Meta'], array_column($filas, 'meta'));
    }

    public function test_el_colaborador_completa_su_propio_seguimiento_sin_pisar_el_del_creador(): void
    {
        [$a, $creador, $colab] = $this->reunionConDosContratistas('Meta Uno', 'Meta Dos');

        Sanctum::actingAs($colab);
        $this->putJson("/api/caracterizacion/actividades/{$a->id}/parte2", ['item26_meta_producto' => 'Meta Dos editada'])->assertOk();
        $this->assertSame('Meta Uno', CaracterizacionSeguimiento::where('actividad_id', $a->id)->where('usuario_id', $creador->id)->value('item26_meta_producto'));
        $this->assertSame('Meta Dos editada', CaracterizacionSeguimiento::where('actividad_id', $a->id)->where('usuario_id', $colab->id)->value('item26_meta_producto'));
        $this->assertSame(2, CaracterizacionSeguimiento::where('actividad_id', $a->id)->count());

        // show: `seguimiento` es el de quien consulta y `seguimientos` trae el de cada contratista
        $r = $this->getJson("/api/caracterizacion/actividades/{$a->id}")->assertOk();
        $this->assertSame('Meta Dos editada', $r->json('seguimiento.item26_meta_producto'));
        $this->assertCount(2, $r->json('seguimientos'));
    }

    public function test_aceptar_la_invitacion_copia_el_contrato_del_colaborador_a_la_reunion(): void
    {
        $o = $this->organizacion();
        $colab = $this->crearUsuario('contratista', $o['sec'], $o['dep1']);
        $this->contratoConBloques($colab, [$this->bloque(['item26_meta_producto' => 'Meta del colaborador'])], 'CPS-555-2026');
        $a = $this->crearActividad($o['contratista'], $o['sec'], $o['dep1']);
        $inv = CaracterizacionColaborador::create(['actividad_id' => $a->id, 'usuario_id' => $colab->id, 'invitado_por' => $o['contratista']->id, 'estado' => CaracterizacionColaborador::ESTADO_PENDIENTE, 'created_at' => now()]);

        Sanctum::actingAs($colab);
        $this->postJson("/api/caracterizacion/colaboraciones/{$inv->id}/responder", ['accion' => 'aceptado'])->assertOk();
        $this->assertSame('Meta del colaborador', CaracterizacionSeguimiento::where('actividad_id', $a->id)->where('usuario_id', $colab->id)->value('item26_meta_producto'));
    }
}
