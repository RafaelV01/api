<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionAsistente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

class FirmasArchivoTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function cuerpo(string $documento, string $firma): array
    {
        return [
            'item1_nombre' => 'Prueba Firma', 'item2_tipo_documento' => 'C.C', 'item3_numero_documento' => $documento, 'item4_cargo_barrio_vereda' => 'Presidente',
            'item5_municipio' => 'YOPAL', 'item6_zona' => 'urbana', 'item7_ubicacion_tipo' => 'Barrio', 'item8_contacto_valor' => '3001234567',
            'item9_genero' => 'Mujer', 'item15_edad' => 30, 'item16_tamano_grupo_familiar' => 3, 'firma_base64' => $firma, 'firma_hash' => hash('sha256', 'f'),
        ];
    }

    public function test_la_firma_nueva_se_guarda_como_archivo_reducido_y_no_como_texto_en_la_base(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);

        $this->postJson("/api/caracterizacion/registrar/{$actividad->slug_acceso}", $this->cuerpo('500100001', $this->firmaDePrueba(1200, 400)))->assertCreated();

        $fila = CaracterizacionAsistente::where('item3_numero_documento', '500100001')->first();
        $this->assertNotNull($fila->firma_ciudadano_archivo);
        $this->assertNull($fila->firma_ciudadano_base64);
        Storage::disk('local')->assertExists($fila->firma_ciudadano_archivo);
        $info = getimagesizefromstring(Storage::disk('local')->get($fila->firma_ciudadano_archivo));
        $this->assertLessThanOrEqual(480, $info[0]); // se redujo a un tamaño razonable
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function test_una_firma_que_no_es_una_imagen_se_rechaza(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);

        foreach (['data:image/png;base64,'.base64_encode('esto no es una imagen'), 'data:text/html;base64,'.base64_encode('<script>alert(1)</script>'), 'cualquier-cosa'] as $mala) {
            $this->postJson("/api/caracterizacion/registrar/{$actividad->slug_acceso}", $this->cuerpo('500100002', $mala))->assertStatus(422)->assertJsonValidationErrors('firma_base64');
        }
        $this->assertSame(0, CaracterizacionAsistente::where('item3_numero_documento', '500100002')->count());
    }

    public function test_el_pdf_y_el_excel_muestran_la_firma_guardada_como_archivo(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $actividad = $this->crearActividad($creador, $sec, $dep);
        $this->postJson("/api/caracterizacion/registrar/{$actividad->slug_acceso}", $this->cuerpo('500100003', $this->firmaDePrueba()))->assertCreated();
        Sanctum::actingAs($creador);

        $this->get("/api/caracterizacion/actividades/{$actividad->id}/pdf")->assertOk();
        $r = $this->get("/api/caracterizacion/actividades/{$actividad->id}/excel")->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'fx').'.xlsx';
        file_put_contents($ruta, $r->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');
        $this->assertGreaterThanOrEqual(2, count($hoja->getDrawingCollection())); // logo + firma
        unlink($ruta);
    }

    public function test_el_comando_pasa_las_firmas_anteriores_a_archivos_sin_perderlas(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);
        $legado = $this->agregarParticipante($actividad, '500100004', ['firma_ciudadano_base64' => $this->firmaDePrueba()]);
        $this->assertNull($legado->firma_ciudadano_archivo);
        $original = $legado->firmaBinaria();

        // Paso 1: crea el archivo y NO toca el base64
        Artisan::call('caracterizacion:migrar-firmas');
        $legado->refresh();
        $this->assertNotNull($legado->firma_ciudadano_archivo);
        $this->assertNotNull($legado->firma_ciudadano_base64);
        Storage::disk('local')->assertExists($legado->firma_ciudadano_archivo);

        // Paso 2: con --limpiar vacía el base64; la firma se sigue leyendo (ahora del archivo)
        Artisan::call('caracterizacion:migrar-firmas', ['--limpiar' => true]);
        $legado->refresh();
        $this->assertNull($legado->firma_ciudadano_base64);
        $this->assertNotNull($legado->firmaBinaria());
        $this->assertNotNull($original);
        $this->assertStringStartsWith('data:image/', $legado->firmaDataUri());
    }

    public function test_una_firma_anterior_en_base64_se_sigue_mostrando_sin_migrar(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);
        $viejo = $this->agregarParticipante($actividad, '500100005', ['firma_ciudadano_base64' => $this->firmaDePrueba()]);

        $this->assertNotNull($viejo->firmaBinaria());
        $this->assertStringStartsWith('data:image/png', $viejo->firmaDataUri());
    }
}
