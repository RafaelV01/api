<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionContrato;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

/** Bloqueo del acceso al terminar el contrato y autocompletado del formulario por documento + fecha de nacimiento. */
class ContratoYAutocompletadoTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function contrato($usuario, string $inicio, string $fin, string $numero = 'CPS-001-2026'): CaracterizacionContrato
    {
        return CaracterizacionContrato::create(['usuario_id' => $usuario->id, 'numero_contrato' => $numero, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'activo' => true]);
    }

    private function entrar($usuario)
    {
        return $this->postJson('/api/login', ['email' => $usuario->email, 'password' => 'Clave12345']);
    }

    public function test_el_contratista_con_contrato_vencido_no_puede_iniciar_sesion(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u = $this->crearUsuario('contratista', $sec, $dep);
        $this->contrato($u, now()->subMonths(6)->toDateString(), now()->subDay()->toDateString());

        $this->entrar($u)->assertForbidden()->assertJsonPath('codigo', 'contrato_no_vigente');
    }

    public function test_el_ultimo_dia_del_contrato_todavia_se_puede_entrar_y_antes_de_iniciar_no(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $hoyFin = $this->crearUsuario('contratista', $sec, $dep);
        $this->contrato($hoyFin, now()->subMonths(3)->toDateString(), now()->toDateString());
        $this->entrar($hoyFin)->assertOk();

        $futuro = $this->crearUsuario('contratista', $sec, $dep);
        $this->contrato($futuro, now()->addDays(5)->toDateString(), now()->addMonths(3)->toDateString());
        $this->entrar($futuro)->assertForbidden();
    }

    public function test_quien_no_tiene_contratos_cargados_o_no_es_contratista_no_se_bloquea(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $this->entrar($this->crearUsuario('contratista', $sec, $dep))->assertOk(); // sin contratos: usuarios anteriores

        $supervisor = $this->crearUsuario('supervisor', $sec, null);
        $this->contrato($supervisor, now()->subYear()->toDateString(), now()->subMonths(6)->toDateString());
        $this->entrar($supervisor)->assertOk(); // el bloqueo es solo para contratistas
    }

    public function test_una_sesion_abierta_se_bloquea_cuando_el_contrato_termina_pero_puede_cerrar_sesion(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u = $this->crearUsuario('contratista', $sec, $dep);
        $this->contrato($u, now()->subMonths(6)->toDateString(), now()->subDay()->toDateString());
        Sanctum::actingAs($u);

        $this->getJson('/api/perfil')->assertForbidden()->assertJsonPath('codigo', 'contrato_no_vigente');
        $this->getJson('/api/caracterizacion/actividades')->assertForbidden();
    }

    public function test_el_administrador_registra_el_contrato_al_editar_al_contratista(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u = $this->crearUsuario('contratista', $sec, $dep);
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));
        $base = ['caracterizacion_rol' => 'contratista', 'caracterizacion_secretaria_id' => $sec->id, 'caracterizacion_dependencia_id' => $dep->id];

        $this->putJson("/api/usuarios/{$u->id}", $base + ['contrato_numero' => 'CPS-045-2026', 'contrato_inicio' => '2026-02-01', 'contrato_fin' => '2026-12-31'])
            ->assertOk()->assertJsonPath('user.contrato.numero', 'CPS-045-2026')->assertJsonPath('user.contrato.fin', '2026-12-31');
        $this->assertSame(1, CaracterizacionContrato::where('usuario_id', $u->id)->count());

        // Editar el contrato lo actualiza (no crea otro)
        $this->putJson("/api/usuarios/{$u->id}", $base + ['contrato_numero' => 'CPS-045-2026', 'contrato_inicio' => '2026-02-01', 'contrato_fin' => '2027-03-31'])->assertOk();
        $this->assertSame(1, CaracterizacionContrato::where('usuario_id', $u->id)->count());
        $this->assertSame('2027-03-31', CaracterizacionContrato::where('usuario_id', $u->id)->first()->fecha_fin->toDateString());

        // Fechas incoherentes o datos incompletos se rechazan
        $this->putJson("/api/usuarios/{$u->id}", $base + ['contrato_numero' => 'X-1', 'contrato_inicio' => '2026-05-01', 'contrato_fin' => '2026-04-01'])
            ->assertStatus(422)->assertJsonValidationErrors('contrato_fin');
        $this->putJson("/api/usuarios/{$u->id}", $base + ['contrato_numero' => 'X-1'])->assertStatus(422);
    }

    // ── Autocompletado ──

    private function registrar(string $slug, string $documento, array $extra = [])
    {
        return $this->postJson("/api/caracterizacion/registrar/{$slug}", $extra + [
            'item1_nombre' => 'Maria Autocompleta', 'item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento,
            'item4_cargo_barrio_vereda' => 'Presidenta', 'item5_municipio' => 'YOPAL', 'item6_zona' => 'urbana',
            'item7_ubicacion_tipo' => 'Barrio', 'item7_ubicacion_detalle' => 'Centro', 'item8_contacto_valor' => '3001234567',
            'item9_genero' => 'Mujer', 'fecha_nacimiento' => '1990-03-15', 'item16_tamano_grupo_familiar' => 4,
            'item11_problematicas' => ['Salud'], 'item14_enfoques' => ['Discapacidad'],
            'acepta_politica' => true, 'firma_base64' => $this->firmaDePrueba(), 'firma_hash' => hash('sha256', 'f'),
        ]);
    }

    private function dosReuniones(): array
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);

        return [$this->crearActividad($creador, $sec, $dep), $this->crearActividad($creador, $sec, $dep)];
    }

    private function buscar(string $slug, string $documento, string $nacimiento)
    {
        return $this->postJson("/api/caracterizacion/buscar-persona/{$slug}", ['item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento, 'fecha_nacimiento' => $nacimiento]);
    }

    public function test_quien_ya_se_registro_recupera_sus_datos_con_documento_y_fecha_de_nacimiento(): void
    {
        [$a1, $a2] = $this->dosReuniones();
        $this->registrar($a1->slug_acceso, '800200001')->assertCreated();

        $r = $this->buscar($a2->slug_acceso, '800200001', '1990-03-15')->assertOk();
        $r->assertJsonPath('item1_nombre', 'Maria Autocompleta')->assertJsonPath('item5_municipio', 'YOPAL')
            ->assertJsonPath('item9_genero', 'Mujer')->assertJsonPath('item11_problematicas.0', 'Salud')->assertJsonPath('item14_enfoques.0', 'Discapacidad');
        // La firma jamás se devuelve ni se reutiliza
        $this->assertStringNotContainsString('firma', strtolower($r->getContent()));
    }

    public function test_si_el_dato_no_coincide_o_no_existe_la_respuesta_es_la_misma(): void
    {
        [$a1, $a2] = $this->dosReuniones();
        $this->registrar($a1->slug_acceso, '800200002')->assertCreated();

        $distinta = $this->buscar($a2->slug_acceso, '800200002', '1991-01-01');
        $inexistente = $this->buscar($a2->slug_acceso, '800299999', '1990-03-15');
        $distinta->assertNotFound();
        $inexistente->assertNotFound();
        $this->assertSame($distinta->json('message'), $inexistente->json('message')); // no permite saber si la cédula existe
    }

    public function test_tras_varios_intentos_fallidos_con_un_documento_se_bloquea_aunque_acierten(): void
    {
        [$a1, $a2] = $this->dosReuniones();
        $this->registrar($a1->slug_acceso, '800200003')->assertCreated();

        foreach (range(1, 5) as $i) {
            $this->buscar($a2->slug_acceso, '800200003', '1980-01-0'.$i)->assertNotFound();
        }
        $this->buscar($a2->slug_acceso, '800200003', '1990-03-15')->assertStatus(429);
    }

    public function test_en_una_reunion_cerrada_no_se_busca(): void
    {
        [$a1, $a2] = $this->dosReuniones();
        $this->registrar($a1->slug_acceso, '800200004')->assertCreated();
        $a2->update(['estado' => 'cerrada']);

        $this->buscar($a2->slug_acceso, '800200004', '1990-03-15')->assertNotFound();
    }
}
