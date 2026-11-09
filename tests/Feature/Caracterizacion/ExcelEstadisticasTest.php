<?php

namespace Tests\Feature\Caracterizacion;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\DatosCaracterizacion;
use Tests\TestCase;

/** Estadísticas con archivos Excel subidos: solo cuentan esos archivos y no se mezclan con las reuniones del sistema. */
class ExcelEstadisticasTest extends TestCase
{
    use DatabaseTransactions, DatosCaracterizacion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Este administrador debe estar solo: las pruebas cuentan las cargas
        \Illuminate\Support\Facades\DB::table('caracterizacion_cargas_excel')->delete();
    }

    /** Excel con la forma del FO-PDD-19: títulos arriba, encabezado «Nombres y Apellidos…» y una fila por persona. */
    private function excel(array $personas, bool $conEncabezado = true): UploadedFile
    {
        $libro = new Spreadsheet();
        $h = $libro->getActiveSheet()->setTitle('FO-PDD-19');
        $h->setCellValue('A1', 'CARACTERIZACIÓN DE CIUDADANÍA');
        if ($conEncabezado) {
            $h->setCellValue('A5', 'No.');
            $h->setCellValue('B5', 'Nombres y Apellidos y/o Nombre de la Entidad (1)');
            $h->setCellValue('D5', 'Número Documento (3)');
            $h->setCellValue('B6', '(1)');
        }
        $fila = 7;
        foreach ($personas as $i => $p) {
            $celdas = ['A' => $i + 1, 'B' => $p['nombre'] ?? null, 'C' => $p['tipo'] ?? 'C.C', 'D' => $p['doc'] ?? null, 'F' => $p['municipio'] ?? 'Yopal'];
            foreach (($p['marcas'] ?? []) as $col) {
                $celdas[$col] = 'X';
            }
            foreach (['AE' => 'edad', 'AL' => 'bien', 'AS' => 'meta', 'AR' => 'programa', 'AN' => 'fecha', 'X' => 'sector'] as $col => $clave) {
                if (isset($p[$clave])) {
                    $celdas[$col] = $p[$clave];
                }
            }
            foreach ($celdas as $col => $valor) {
                if ($valor !== null) {
                    $h->setCellValue($col.$fila, $valor);
                }
            }
            $fila++;
        }
        $ruta = tempnam(sys_get_temp_dir(), 'tx').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'caracterizacion.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** G urbana, H rural · K Mujer, L Hombre, M No binario · S Institucional, T Ambiental, U Salud, V Cultural · AA Discapacidad */
    private function datosBase(): array
    {
        return [
            ['nombre' => 'Ana Uno', 'doc' => '1001', 'marcas' => ['G', 'K', 'U', 'T'], 'edad' => 30, 'bien' => 'Capacitación', 'meta' => 'Meta A', 'municipio' => 'Yopal'],
            ['nombre' => 'Beto Dos', 'doc' => '1002', 'marcas' => ['H', 'L', 'U'], 'edad' => 45, 'bien' => 'Asistencia técnica', 'meta' => 'Meta B', 'municipio' => 'Aguazul'],
            ['nombre' => 'Carla Tres', 'doc' => '1003', 'marcas' => ['G', 'K', 'V', 'AA'], 'edad' => 20, 'municipio' => 'Yopal'],
            ['nombre' => 'Ana Uno (otra fila)', 'doc' => '1001', 'marcas' => ['G', 'K'], 'edad' => 30, 'bien' => 'Asistencia técnica', 'meta' => 'Meta B', 'municipio' => 'Yopal'],
            ['nombre' => 'Sin documento', 'doc' => null, 'marcas' => ['G']],
        ];
    }

    private function subir(UploadedFile $archivo, string $nombre = ''): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/caracterizacion/excel/cargas', ['archivo' => $archivo, 'nombre' => $nombre], ['Accept' => 'application/json']);
    }

    private function admin()
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        $u = $this->crearUsuario('administrador', $sec, $dep, true);
        Sanctum::actingAs($u);

        return [$u, $sec, $dep];
    }

    private function stats(string $consulta = ''): array
    {
        return $this->getJson('/api/caracterizacion/excel/estadisticas/por-variables'.($consulta ? '?'.$consulta : ''))->assertOk()->json();
    }

    public function test_solo_los_administradores_suben_y_consultan_archivos(): void
    {
        [$sec, $dep] = $this->crearSecretariaYDependencia();
        Sanctum::actingAs($this->crearUsuario('contratista', $sec, $dep));
        $this->getJson('/api/caracterizacion/excel/cargas')->assertForbidden();
        $this->getJson('/api/caracterizacion/excel/estadisticas/por-variables')->assertForbidden();
        Sanctum::actingAs($this->crearUsuario('supervisor', $sec, null));
        $this->getJson('/api/caracterizacion/excel/cargas')->assertForbidden();

        $this->admin();
        $this->getJson('/api/caracterizacion/excel/cargas')->assertOk();
    }

    public function test_el_archivo_se_lee_y_las_filas_sin_documento_se_omiten_con_motivo(): void
    {
        $this->admin();
        $r = $this->subir($this->excel($this->datosBase()), 'Taller de prueba')->assertCreated();
        $this->assertSame(4, $r->json('total_filas'));
        $this->assertSame(1, $r->json('filas_omitidas'));

        $lista = $this->getJson('/api/caracterizacion/excel/cargas')->assertOk()->json();
        $this->assertCount(1, $lista);
        $this->assertSame('Taller de prueba', $lista[0]['nombre']);
        $this->assertSame(3, $lista[0]['personas']); // Ana está en dos filas pero es una persona
        $this->assertSame('sin número de documento', $lista[0]['omitidas_detalle'][0]['motivo']);
    }

    public function test_las_estadisticas_cuentan_personas_unicas_y_respetan_los_filtros(): void
    {
        $this->admin();
        $this->subir($this->excel($this->datosBase()))->assertCreated();

        $s = $this->stats();
        $this->assertSame(3, $s['total_personas']);
        $this->assertSame(4, $s['total_participaciones']);
        $this->assertSame(1, $s['total_actividades']); // archivos
        $genero = collect($s['desglose']['genero'])->pluck('total', 'valor')->all();
        $this->assertSame(['Mujer' => 2, 'Hombre' => 1], $genero); // Ana (su última fila) y Carla; Beto
        $this->assertSame(['YOPAL' => 2, 'AGUAZUL' => 1], collect($s['desglose']['municipio'])->pluck('total', 'valor')->all());

        // Filtrar por problemática Salud: personas con salud y SOLO esa opción en el desglose
        $f = $this->stats('problematica[]=Salud');
        $this->assertSame(2, $f['total_personas']);
        $this->assertSame(['Salud'], collect($f['desglose']['problematica'])->pluck('valor')->all());

        // Cruce («y») entre variables
        $this->assertSame(1, $this->stats('problematica[]=Salud&genero[]=Hombre')['total_personas']);
        // Bienes y servicios: cuenta personas distintas por valor
        $bien = collect($s['desglose']['bien_servicio'])->pluck('total', 'valor')->all();
        $this->assertSame(['Asistencia técnica' => 2, 'Capacitación' => 1], $bien);
        $this->assertSame(2, $s['personas_con_seguimiento']);
    }

    public function test_los_datos_de_excel_no_se_mezclan_con_las_reuniones_del_sistema(): void
    {
        [, $sec, $dep] = $this->admin();
        $actividad = $this->crearActividad($this->crearUsuario('contratista', $sec, $dep), $sec, $dep);
        $this->agregarParticipante($actividad, '777000001');
        $this->agregarParticipante($actividad, '777000002');

        // Sin archivos subidos, las estadísticas de Excel están vacías aunque el sistema tenga reuniones
        $this->assertSame(0, $this->stats()['total_personas']);

        $this->subir($this->excel($this->datosBase()))->assertCreated();
        $this->assertSame(3, $this->stats()['total_personas']); // solo el Excel

        // Y las del sistema siguen contando solo lo suyo
        $sistema = $this->getJson("/api/caracterizacion/estadisticas/por-variables?secretaria_id={$sec->id}")->assertOk()->json();
        $this->assertSame(2, $sistema['total_personas']);
    }

    public function test_se_puede_elegir_con_cuales_archivos_calcular_y_eliminar_uno_borra_sus_datos(): void
    {
        $this->admin();
        $a = $this->subir($this->excel($this->datosBase()), 'A')->json('id');
        $b = $this->subir($this->excel([['nombre' => 'Dario Cuatro', 'doc' => '2001', 'marcas' => ['H', 'L'], 'municipio' => 'Mani']]), 'B')->json('id');

        $this->assertSame(4, $this->stats()['total_personas']);
        $this->assertSame(1, $this->stats("cargas={$b}")['total_personas']);
        $this->assertSame(3, $this->stats("cargas={$a}")['total_personas']);
        $this->assertSame(4, $this->stats("cargas={$a},{$b}")['total_personas']);

        $this->deleteJson("/api/caracterizacion/excel/cargas/{$a}")->assertOk();
        $this->assertSame(1, $this->stats()['total_personas']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('caracterizacion_carga_registros')->where('carga_id', $a)->count());
    }

    public function test_un_archivo_que_no_es_del_formato_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->admin();
        $this->subir($this->excel($this->datosBase(), false))->assertStatus(422)->assertJsonValidationErrors('archivo');

        $falso = UploadedFile::fake()->createWithContent('datos.xlsx', 'esto no es un excel');
        $this->subir($falso)->assertStatus(422);

        $this->subir($this->excel([]))->assertStatus(422); // encabezado sin filas
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('caracterizacion_cargas_excel')->count());
    }

    public function test_las_opciones_de_cada_variable_salen_de_los_archivos_subidos_y_se_exporta_el_resumen(): void
    {
        $this->admin();
        $this->subir($this->excel($this->datosBase()))->assertCreated();

        $variables = collect($this->getJson('/api/caracterizacion/excel/estadisticas/variables')->assertOk()->json())->keyBy('clave');
        $this->assertEqualsCanonicalizing(['Capacitación', 'Asistencia técnica'], $variables['bien_servicio']['opciones']);
        $this->assertEqualsCanonicalizing(['Salud', 'Ambiental', 'Cultural'], $variables['problematica']['opciones']);
        $this->assertContains('Mujer', $variables['genero']['opciones']);

        $r = $this->get('/api/caracterizacion/excel/estadisticas/por-variables/exportar?genero[]=Mujer')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));
    }
}
