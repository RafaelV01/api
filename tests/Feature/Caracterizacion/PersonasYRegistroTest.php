<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionPersona;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

class PersonasYRegistroTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local'); // las firmas no se escriben en el disco real
    }

    private function cuerpo(string $documento, array $cambios = []): array
    {
        return $cambios + [
            'item1_nombre' => 'Prueba Persona Unica', 'item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento,
            'item4_cargo_barrio_vereda' => 'Presidente', 'item5_municipio' => 'YOPAL', 'item6_zona' => 'urbana',
            'item7_ubicacion_tipo' => 'Barrio', 'item7_ubicacion_detalle' => 'Centro', 'item8_contacto_valor' => '3001234567',
            'item9_genero' => 'Mujer', 'item15_edad' => 30, 'item16_tamano_grupo_familiar' => 3,
            'firma_base64' => $this->firmaDePrueba(), 'firma_hash' => hash('sha256', 'f'),
        ];
    }

    public function test_la_misma_persona_no_puede_registrarse_dos_veces_en_una_reunion(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);

        $this->postJson("/api/caracterizacion/registrar/{$actividad->slug_acceso}", $this->cuerpo('900100200'))->assertCreated();
        $this->postJson("/api/caracterizacion/registrar/{$actividad->slug_acceso}", $this->cuerpo('900100200'))
            ->assertStatus(422)->assertJsonPath('errors.item3_numero_documento.0', 'Ya hay un registro con ese documento en esta reunión.');
    }

    public function test_en_dos_reuniones_hay_una_sola_ficha_con_los_datos_mas_recientes(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $a1 = $this->crearActividad($creador, $sec, $dep);
        $a2 = $this->crearActividad($creador, $sec, $dep);

        $this->postJson("/api/caracterizacion/registrar/{$a1->slug_acceso}", $this->cuerpo('900100201'))->assertCreated();
        $this->postJson("/api/caracterizacion/registrar/{$a2->slug_acceso}", $this->cuerpo('900100201', ['item5_municipio' => 'AGUAZUL', 'item15_edad' => 31]))->assertCreated();

        $fichas = CaracterizacionPersona::where('numero_documento', '900100201')->get();
        $this->assertCount(1, $fichas);
        $this->assertSame(2, $fichas[0]->participaciones);
        $this->assertSame('AGUAZUL', $fichas[0]->municipio);
        $this->assertSame(31, $fichas[0]->edad);
        // Cada reunión conserva lo que se declaró ese día
        $this->assertSame('YOPAL', $a1->asistentes()->first()->item5_municipio);
    }

    public function test_el_administrador_busca_personas_y_ve_su_historial_sin_firmas(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $this->agregarParticipante($this->crearActividad($creador, $sec, $dep), '900100202');
        $this->agregarParticipante($this->crearActividad($creador, $sec, $dep), '900100202');
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $busqueda = $this->getJson('/api/caracterizacion/personas?q=900100202')->assertOk();
        $this->assertSame(1, $busqueda->json('total'));
        $id = $busqueda->json('data.0.id');

        $ficha = $this->getJson("/api/caracterizacion/personas/{$id}")->assertOk();
        $this->assertCount(2, $ficha->json('participaciones'));
        $this->assertStringNotContainsString('firma_', $ficha->getContent());
    }

    public function test_un_contratista_no_puede_ver_el_listado_de_personas(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));

        $this->getJson('/api/caracterizacion/personas')->assertForbidden();
    }
}
