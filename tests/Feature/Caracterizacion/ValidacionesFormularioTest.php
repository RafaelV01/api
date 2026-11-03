<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionAsistente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

/** Reglas del formulario público: género con «prefiero no decirlo», grupo familiar de 2 dígitos, fecha de nacimiento y total automático. */
class ValidacionesFormularioTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function cuerpo(string $documento, array $cambios = []): array
    {
        return $cambios + [
            'item1_nombre' => 'Prueba Validaciones', 'item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento,
            'item4_cargo_barrio_vereda' => 'Presidente', 'item5_municipio' => 'YOPAL', 'item6_zona' => 'urbana',
            'item7_ubicacion_tipo' => 'Barrio', 'item8_contacto_valor' => '3001234567',
            'item9_genero' => 'Mujer', 'fecha_nacimiento' => '1990-03-15', 'item16_tamano_grupo_familiar' => 4,
            'acepta_politica' => true, 'firma_base64' => $this->firmaDePrueba(), 'firma_hash' => hash('sha256', 'f'),
        ];
    }

    private function actividad()
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();

        return [$this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep), $sec, $dep];
    }

    public function test_prefiero_no_decirlo_es_un_genero_valido(): void
    {
        [$a] = $this->actividad();

        $this->postJson("/api/caracterizacion/registrar/{$a->slug_acceso}", $this->cuerpo('700100001', ['item9_genero' => 'Prefiero no decirlo']))->assertCreated();
        $this->assertSame('Prefiero no decirlo', CaracterizacionAsistente::where('item3_numero_documento', '700100001')->value('item9_genero'));
    }

    public function test_el_tamano_del_grupo_familiar_admite_solo_1_a_99(): void
    {
        [$a] = $this->actividad();
        $url = "/api/caracterizacion/registrar/{$a->slug_acceso}";

        foreach (['0', '100', '123', 'ab', '1.5', '-3', ''] as $malo) {
            $this->postJson($url, $this->cuerpo('700100002', ['item16_tamano_grupo_familiar' => $malo]))
                ->assertStatus(422)->assertJsonValidationErrors('item16_tamano_grupo_familiar');
        }
        $this->postJson($url, $this->cuerpo('700100002', ['item16_tamano_grupo_familiar' => '99']))->assertCreated();
        $this->postJson($url, $this->cuerpo('700100003', ['item16_tamano_grupo_familiar' => 7]))->assertCreated();
    }

    public function test_la_fecha_de_nacimiento_se_valida_y_la_edad_se_calcula(): void
    {
        [$a] = $this->actividad();
        $url = "/api/caracterizacion/registrar/{$a->slug_acceso}";

        foreach ([now()->addDay()->toDateString(), now()->subYears(121)->toDateString(), '15/03/1990', 'no-es-fecha', '1990-13-45'] as $mala) {
            $this->postJson($url, $this->cuerpo('700100004', ['fecha_nacimiento' => $mala]))->assertStatus(422)->assertJsonValidationErrors('fecha_nacimiento');
        }
        // Sin fecha de nacimiento ya no se acepta (antes bastaba la edad)
        $sinFecha = $this->cuerpo('700100004');
        unset($sinFecha['fecha_nacimiento']);
        $this->postJson($url, $sinFecha + ['item15_edad' => 30])->assertStatus(422)->assertJsonValidationErrors('fecha_nacimiento');

        // La reunión fue hace 3 días: quien cumple 25 años dentro de 2 días tenía 24 ese día
        $this->postJson($url, $this->cuerpo('700100005', ['fecha_nacimiento' => now()->subYears(25)->addDays(2)->toDateString()]))->assertCreated();
        $this->assertSame(24, CaracterizacionAsistente::where('item3_numero_documento', '700100005')->value('item15_edad'));
        // Quien ya los cumplió antes de la reunión tenía 25
        $this->postJson($url, $this->cuerpo('700100006', ['fecha_nacimiento' => now()->subYears(25)->subDays(10)->toDateString()]))->assertCreated();
        $this->assertSame(25, CaracterizacionAsistente::where('item3_numero_documento', '700100006')->value('item15_edad'));
        // Una edad enviada a mano se ignora: manda la fecha de nacimiento
        $this->postJson($url, $this->cuerpo('700100007', ['item15_edad' => 99]))->assertCreated();
        $this->assertNotSame(99, CaracterizacionAsistente::where('item3_numero_documento', '700100007')->value('item15_edad'));
    }

    public function test_el_total_de_beneficiarios_del_excel_es_el_numero_de_asistentes(): void
    {
        [$a, $sec, $dep] = $this->actividad();
        foreach (['700100011', '700100012', '700100013'] as $doc) {
            $this->postJson("/api/caracterizacion/registrar/{$a->slug_acceso}", $this->cuerpo($doc))->assertCreated();
        }
        Sanctum::actingAs(\App\Models\User::find($a->creador_id));

        $r = $this->get("/api/caracterizacion/actividades/{$a->id}/excel")->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'fx').'.xlsx';
        file_put_contents($ruta, $r->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');
        unlink($ruta);

        // Filas de datos desde la 13 (8 + 5 filas del encabezado); columna AZ = ítem 31
        foreach ([13, 14, 15] as $fila) {
            $this->assertSame(3, (int) $hoja->getCell("AZ{$fila}")->getValue());
        }
    }

    public function test_el_total_de_beneficiarios_ya_no_se_puede_escribir_a_mano(): void
    {
        [$a, $sec, $dep] = $this->actividad();
        Sanctum::actingAs(\App\Models\User::find($a->creador_id));

        $this->putJson("/api/caracterizacion/actividades/{$a->id}/parte2", ['item31_total_beneficiarios' => 500, 'item22_descripcion_beneficio' => 'Apoyo'])->assertOk();
        $this->assertNull(optional($a->fresh()->seguimiento)->item31_total_beneficiarios);
    }

    public function test_sin_aceptar_la_politica_de_datos_no_hay_registro_y_queda_constancia_al_aceptar(): void
    {
        [$a] = $this->actividad();
        $url = "/api/caracterizacion/registrar/{$a->slug_acceso}";

        $sinAceptar = $this->cuerpo('700100031');
        unset($sinAceptar['acepta_politica']);
        $this->postJson($url, $sinAceptar)->assertStatus(422)->assertJsonValidationErrors('acepta_politica');
        $this->postJson($url, $this->cuerpo('700100031', ['acepta_politica' => false]))->assertStatus(422)->assertJsonValidationErrors('acepta_politica');
        $this->assertSame(0, CaracterizacionAsistente::where('item3_numero_documento', '700100031')->count());

        $this->postJson($url, $this->cuerpo('700100031'))->assertCreated();
        $fila = CaracterizacionAsistente::where('item3_numero_documento', '700100031')->first();
        $this->assertSame(\App\Http\Controllers\Api\Caracterizacion\PublicController::POLITICA_VERSION, $fila->consentimiento_version);
        $this->assertNotNull($fila->consentimiento_en);
    }

    public function test_la_firma_electronica_por_consentimiento_reemplaza_al_lienzo(): void
    {
        [$a] = $this->actividad();
        $url = "/api/caracterizacion/registrar/{$a->slug_acceso}";
        $sinLienzo = fn (array $extra) => $extra + $this->cuerpo('700100032', ['item1_nombre' => 'José Ñandú Pérez', 'firma_base64' => null, 'firma_hash' => null]);

        // Sin la casilla de aceptación no vale
        $this->postJson($url, $sinLienzo(['firma_modo' => 'electronica']))->assertStatus(422)->assertJsonValidationErrors('firma_electronica_acepta');
        // Con lienzo vacío y modo normal tampoco
        $this->postJson($url, $sinLienzo([]))->assertStatus(422)->assertJsonValidationErrors('firma_base64');

        $this->postJson($url, $sinLienzo(['firma_modo' => 'electronica', 'firma_electronica_acepta' => true]))->assertCreated();
        $fila = CaracterizacionAsistente::where('item3_numero_documento', '700100032')->first();
        $this->assertSame('electronica', $fila->firma_modo);
        $this->assertSame(64, strlen($fila->firma_ciudadano_hash));
        Storage::disk('local')->assertExists($fila->firma_ciudadano_archivo);
        $this->assertNotNull(getimagesizefromstring(Storage::disk('local')->get($fila->firma_ciudadano_archivo))); // es una imagen real, lista para el PDF/Excel
    }

    public function test_la_firma_de_quien_dirige_sale_en_el_excel_y_el_pdf(): void
    {
        [$a] = $this->actividad();
        $a->update(['firma_expositor_base64' => $this->firmaDePrueba(), 'firma_expositor_hash' => str_repeat('a', 64)]);
        $this->postJson("/api/caracterizacion/registrar/{$a->slug_acceso}", $this->cuerpo('700100033'))->assertCreated();
        Sanctum::actingAs(\App\Models\User::find($a->creador_id));

        $leer = function () use ($a) {
            $r = $this->get("/api/caracterizacion/actividades/{$a->id}/excel")->assertOk();
            $ruta = tempnam(sys_get_temp_dir(), 'fx').'.xlsx';
            file_put_contents($ruta, $r->getContent());
            $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');
            unlink($ruta);

            return $hoja;
        };
        $con = $leer();
        $nombres = collect($con->getDrawingCollection())->map(fn ($d) => $d->getName())->all();
        $this->assertContains('FirmaExpositor', $nombres);
        $this->assertStringContainsString('FIRMA:', (string) $con->getCell('A8')->getValue().(string) $con->getCell('A3')->getValue());

        $a->update(['firma_expositor_base64' => null]);
        $sin = collect($leer()->getDrawingCollection())->map(fn ($d) => $d->getName())->all();
        $this->assertNotContains('FirmaExpositor', $sin);
        $this->get("/api/caracterizacion/actividades/{$a->id}/pdf")->assertOk();
    }

    public function test_las_estadisticas_cuentan_a_quienes_prefieren_no_decirlo(): void
    {
        [$a, $sec, $dep] = $this->actividad();
        $this->agregarParticipante($a, '700100021', ['item9_genero' => 'Prefiero no decirlo']);
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));

        $dash = $this->getJson("/api/caracterizacion/estadisticas?secretaria_id={$sec->id}")->assertOk();
        $this->assertSame(1, collect($dash->json('por_municipio'))->sum('genero.prefiere_no_decirlo'));
    }
}
