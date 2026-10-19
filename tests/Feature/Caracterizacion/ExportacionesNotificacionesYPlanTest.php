<?php

namespace Tests\Feature\Caracterizacion;

use App\Models\CaracterizacionNotificacion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

class ExportacionesNotificacionesYPlanTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    public function test_el_excel_de_una_actividad_trae_logo_encabezado_y_columnas_ajustadas(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $actividad = $this->crearActividad($creador, $sec, $dep);
        $this->agregarParticipante($actividad, '600100001', ['item1_nombre' => 'ZZ Ciudadano Excel', 'item5_municipio' => 'OROCUE']);
        Sanctum::actingAs($creador);

        $r = $this->get("/api/caracterizacion/actividades/{$actividad->id}/excel")->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'ex').'.xlsx';
        file_put_contents($ruta, $r->getContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheetByName('FO-PDD-19');

        $this->assertStringContainsString('CONTROL DE ASISTENCIA TECNICA', (string) $hoja->getCell('P1')->getValue());
        $this->assertSame('FO-PDD-19', $hoja->getCell('P2')->getValue());
        $this->assertSame('ZZ Ciudadano Excel', $hoja->getCell('B13')->getValue());
        $this->assertSame('85230', (string) $hoja->getCell('AJ13')->getValue()); // código DANE del municipio de la persona
        $this->assertFalse($hoja->getColumnDimension('AG')->getVisible()); // Canal de comunicación (17), quitado
        $this->assertFalse($hoja->getColumnDimension('AH')->getVisible()); // Idiomas (18), quitado
        $this->assertGreaterThanOrEqual(3.6, $hoja->getColumnDimension('S')->getWidth()); // la «X» de Institucional cabe
        $this->assertGreaterThanOrEqual(1, count($hoja->getDrawingCollection())); // logo (y firma)
        unlink($ruta);
    }

    public function test_una_exportacion_en_segundo_plano_se_pide_se_procesa_y_se_descarga_solo_por_su_dueno(): void
    {
        Storage::fake('local');
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $this->agregarParticipante($this->crearActividad($creador, $sec, $dep), '600100002');
        Sanctum::actingAs($creador);

        $id = $this->postJson('/api/caracterizacion/exportaciones', ['tipo' => 'consolidado', 'parametros' => ['secretaria_id' => (string) $sec->id]])
            ->assertStatus(202)->assertJsonPath('estado', 'pendiente')->json('id');

        // En producción el generador es otro proceso; aquí corre en el mismo y al terminar deja la sesión sin usuario, así que se vuelve a autenticar.
        Artisan::call('caracterizacion:procesar-exportaciones', ['--una-vez' => true]);
        Sanctum::actingAs($creador);

        $this->getJson("/api/caracterizacion/exportaciones/{$id}")->assertOk()->assertJsonPath('estado', 'listo');
        $archivo = $this->get("/api/caracterizacion/exportaciones/{$id}/descargar")->assertOk();
        $this->assertStringStartsWith('PK', $archivo->streamedContent());

        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));
        $this->getJson("/api/caracterizacion/exportaciones/{$id}")->assertNotFound();
    }

    public function test_solo_el_administrador_pide_el_listado_de_estadisticas_por_variables(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));

        $this->postJson('/api/caracterizacion/exportaciones', ['tipo' => 'variables'])->assertForbidden();
    }

    public function test_aprobar_o_rechazar_avisa_al_creador_y_las_invitaciones_avisan_al_invitado(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $creador = $this->crearUsuario('contratista', $sec, $dep);
        $invitado = $this->crearUsuario('contratista', $sec, $dep);
        $actividad = $this->crearActividad($creador, $sec, $dep, ['estado_aprobacion' => 'pendiente', 'tema' => 'ZZ Taller de prueba']);

        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));
        $this->postJson('/api/caracterizacion/aprobaciones', ['tipo_objetivo' => 'actividad', 'objetivo_id' => $actividad->id, 'accion' => 'rechazado', 'observacion' => 'Faltan firmas'])->assertOk();

        $aviso = CaracterizacionNotificacion::where('usuario_id', $creador->id)->first();
        $this->assertNotNull($aviso);
        $this->assertSame('Tu actividad fue rechazada', $aviso->titulo);
        $this->assertStringContainsString('Faltan firmas', $aviso->mensaje);

        Sanctum::actingAs($creador);
        $this->postJson("/api/caracterizacion/actividades/{$actividad->id}/colaboradores", ['usuario_id' => $invitado->id])->assertCreated();
        $this->assertSame(1, CaracterizacionNotificacion::where('usuario_id', $invitado->id)->where('tipo', 'invitacion')->count());

        // La campana: cuenta, lista y marca como leídas (solo las propias)
        Sanctum::actingAs($creador);
        $this->getJson('/api/caracterizacion/notificaciones')->assertOk()->assertJsonPath('no_leidas', 1);
        $this->postJson('/api/caracterizacion/notificaciones/leer-todas')->assertOk();
        $this->getJson('/api/caracterizacion/notificaciones')->assertJsonPath('no_leidas', 0);
        Sanctum::actingAs($invitado);
        $this->getJson('/api/caracterizacion/notificaciones')->assertJsonPath('no_leidas', 1);
    }

    public function test_el_plan_de_desarrollo_se_importa_sin_duplicar_y_el_arbol_usa_la_vigencia_mas_reciente(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        Sanctum::actingAs($this->crearUsuario('administrador', $sec, $dep, true));
        $csv = "sector;programa;meta_producto;orden\nZZ Sector;9999 - ZZ Programa;Meta uno de prueba;90001\nZZ Sector;9999 - ZZ Programa;Meta dos de prueba;90002\n";
        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('plan.csv', $csv);

        $this->post('/api/caracterizacion/plan-desarrollo/admin/importar', ['archivo' => $archivo, 'vigencia' => '2099-2102'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('nuevas', 2);
        $this->post('/api/caracterizacion/plan-desarrollo/admin/importar', ['archivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('plan.csv', $csv), 'vigencia' => '2099-2102'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('nuevas', 0)->assertJsonPath('procesadas', 2);
        $this->assertSame(2, DB::table('caracterizacion_plan_desarrollo')->where('vigencia', '2099-2102')->count());

        $arbol = $this->getJson('/api/caracterizacion/plan-desarrollo')->assertOk()->json();
        $this->assertSame('ZZ Sector', $arbol[0]['sector']); // la vigencia más reciente es la única que se ofrece
        $this->assertCount(2, $arbol[0]['programas'][0]['metas']);
    }
}
