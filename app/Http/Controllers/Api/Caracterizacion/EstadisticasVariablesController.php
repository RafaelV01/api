<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionOpcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Estadísticas por VARIABLES: el administrador arma una consulta como «mujeres cabeza de hogar,
 * población indígena, jóvenes, que participaron en tal programa o recibieron tal bien o servicio»
 * eligiendo, por categoría, una o varias opciones.
 *
 *   - Dentro de una categoría, las opciones se suman (O): género = Mujer O Hombre.
 *   - Entre categorías se cruzan (Y): género = Mujer Y enfoque = Cabeza de Hogar Y étnico = Indígenas.
 *
 * Solo devuelve conteos (nunca nombres ni documentos de ciudadanos). Por defecto cuentan las
 * actividades aprobadas, igual que EstadisticasController.
 *
 * Los ítems de la Parte 2 (programa, bien o servicio, …) se leen del seguimiento propio del
 * ciudadano si lo tiene y, si no, del seguimiento general de la actividad.
 */
class EstadisticasVariablesController extends Controller
{
    /** Rangos de edad de CaracterizacionAsistente::calcularGrupoEtareo(). */
    private const GRUPOS_ETAREOS = [
        'Primera infancia' => [0, 5],
        'Infancia' => [6, 11],
        'Adolescencia' => [12, 17],
        'Jóvenes' => [18, 28],
        'Adultos' => [29, 60],
        'Mayores' => [61, 120],
    ];

    /** Variables de Parte 2: columna del seguimiento → etiqueta. */
    private const VARIABLES_PARTE2 = [
        'categoria' => ['item21_categoria', 'Beneficio — Categoría (21.1)'],
        'bien_servicio' => ['item21_bien_servicio', 'Beneficio — Bien y/o servicio (21.2)'],
        'gestion_inversion' => ['item24_gestion_inversion', 'Gestión / Inversión (24)'],
        'sector_plan' => ['item26_sector', 'Plan de Desarrollo — Sector (26.1)'],
        'programa' => ['item26_programa', 'Plan de Desarrollo — Programa (26.2)'],
        'meta_producto' => ['item26_meta_producto', 'Plan de Desarrollo — Meta producto (26.3)'],
        'proyecto' => ['item27_nombre_proyecto', 'Proyecto de inversión (27)'],
    ];

    /** Variables del ciudadano (Parte 1) con su columna, etiqueta y categoría del catálogo. */
    private const VARIABLES_PARTE1 = [
        'zona' => ['item6_zona', 'Zona (6)', null],
        'genero' => ['item9_genero', 'Enfoque de género (9)', 'genero'],
        'etnico' => ['item10_etnico', 'Enfoque étnico (10)', 'etnico'],
        'sector' => ['item12_sector_organizacion', 'Sector de la organización (12)', 'sector_organizacion'],
        'clasificacion' => ['item13_clasificacion_organizacion', 'Clasificación de la organización (13)', 'clasificacion_organizacion'],
        'municipio' => ['item5_municipio', 'Municipio (5)', 'municipio'],
    ];

    /** Variables multivalor (un ciudadano puede tener varias): tabla pivote y categoría. */
    private const VARIABLES_PIVOTE = [
        'problematica' => ['caracterizacion_asistente_problematicas', 'Problemática a atender (11)', 'problematica'],
        'enfoque' => ['caracterizacion_asistente_enfoques', 'Enfoque diferencial / poblacional (14)', 'enfoque_diferencial'],
    ];

    /** Catálogo de variables y opciones para que el panel arme sus casillas. */
    public function variables()
    {
        $opcionesCatalogo = fn (string $categoria) => CaracterizacionOpcion::categoria($categoria)->pluck('valor')->values()->all();
        $grupos = [];

        $grupos[] = ['clave' => 'zona', 'etiqueta' => 'Zona (6)', 'opciones' => ['urbana', 'rural'], 'parte' => 1];
        foreach (['genero', 'etnico', 'sector', 'clasificacion'] as $clave) {
            [, $etiqueta, $categoria] = self::VARIABLES_PARTE1[$clave];
            $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'opciones' => $opcionesCatalogo($categoria), 'parte' => 1];
        }
        foreach (self::VARIABLES_PIVOTE as $clave => [, $etiqueta, $categoria]) {
            $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'opciones' => $opcionesCatalogo($categoria), 'parte' => 1];
        }
        $grupos[] = ['clave' => 'grupo_etareo', 'etiqueta' => 'Grupo etáreo (15)', 'opciones' => array_keys(self::GRUPOS_ETAREOS), 'parte' => 1];
        $grupos[] = ['clave' => 'municipio', 'etiqueta' => 'Municipio (5)', 'opciones' => $opcionesCatalogo('municipio'), 'parte' => 1];

        // Parte 2: no hay catálogo fijo (texto libre o plan de desarrollo), se ofrecen los valores ya usados.
        foreach (self::VARIABLES_PARTE2 as $clave => [$columna, $etiqueta]) {
            $valores = DB::query()->fromSub(
                DB::table('caracterizacion_seguimientos')->select("{$columna} as v")
                    ->unionAll(DB::table('caracterizacion_seguimiento_asistentes')->select("{$columna} as v")),
                'x'
            )->whereNotNull('v')->where('v', '!=', '')->distinct()->orderBy('v')->limit(300)->pluck('v')->all();
            $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'opciones' => $valores, 'parte' => 2];
        }

        return response()->json($grupos);
    }

    public function index(Request $request)
    {
        return response()->json($this->calcular($request));
    }

    private function calcular(Request $request): array
    {
        $base = $this->consultaBase($request);

        $total = (clone $base)->count('a.id');
        $conParte2 = (clone $base)->whereRaw('(sa.id IS NOT NULL OR sg.id IS NOT NULL)')->count('a.id');

        $desglose = [];
        foreach (['zona', 'genero', 'etnico', 'clasificacion', 'sector', 'municipio'] as $clave) {
            [$columna] = self::VARIABLES_PARTE1[$clave];
            $desglose[$clave] = (clone $base)->whereNotNull("a.{$columna}")->where("a.{$columna}", '!=', '')
                ->select("a.{$columna} as valor")->selectRaw('COUNT(*) as total')
                ->groupBy("a.{$columna}")->orderByDesc('total')->get()
                ->map(fn ($f) => ['valor' => $f->valor, 'total' => (int) $f->total])->all();
        }

        foreach (self::VARIABLES_PIVOTE as $clave => [$tabla]) {
            $desglose[$clave] = (clone $base)->join("{$tabla} as p", 'p.asistente_id', '=', 'a.id')
                ->select('p.valor')->selectRaw('COUNT(*) as total')->groupBy('p.valor')->orderByDesc('total')->get()
                ->map(fn ($f) => ['valor' => $f->valor, 'total' => (int) $f->total])->all();
        }

        $desglose['grupo_etareo'] = [];
        foreach (self::GRUPOS_ETAREOS as $nombre => [$min, $max]) {
            $n = (clone $base)->whereBetween('a.item15_edad', [max($min, 1), $max])->count('a.id');
            if ($n > 0) {
                $desglose['grupo_etareo'][] = ['valor' => $nombre, 'total' => $n];
            }
        }

        foreach (self::VARIABLES_PARTE2 as $clave => [$columna]) {
            $expr = $this->expresionParte2($columna);
            $desglose[$clave] = (clone $base)->whereRaw("{$expr} IS NOT NULL AND {$expr} != ''")
                ->selectRaw("{$expr} as valor")->selectRaw('COUNT(*) as total')
                ->groupByRaw($expr)->orderByDesc('total')->limit(50)->get()
                ->map(fn ($f) => ['valor' => $f->valor, 'total' => (int) $f->total])->all();
        }

        $actividades = (clone $base)->distinct()->count('a.actividad_id');

        return [
            'total_personas' => $total,
            'total_actividades' => $actividades,
            'personas_con_seguimiento' => $conParte2,
            'desglose' => $desglose,
            'filtros' => $this->filtrosRecibidos($request),
        ];
    }

    /** Exporta el mismo resultado a Excel: una hoja de filtros y resumen, una por desglose. */
    public function exportar(Request $request)
    {
        $r = $this->calcular($request);
        $etiquetas = $this->etiquetas();

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Resumen');
        $hoja->fromArray([['Estadísticas por variables — Caracterización de Ciudadanía']], null, 'A1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $fila = 3;
        foreach ([['Personas', $r['total_personas']], ['Actividades', $r['total_actividades']], ['Personas con seguimiento (Parte 2)', $r['personas_con_seguimiento']]] as $linea) {
            $hoja->fromArray($linea, null, "A{$fila}");
            $fila++;
        }
        $fila++;
        $hoja->setCellValue("A{$fila}", 'Filtros aplicados');
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
        $fila++;
        foreach ($r['filtros'] as $clave => $valores) {
            $hoja->setCellValue("A{$fila}", $this->sinFormula($etiquetas[$clave] ?? $clave));
            $hoja->setCellValue("B{$fila}", $this->sinFormula(is_array($valores) ? implode(', ', $valores) : (string) $valores));
            $fila++;
        }
        if (!$r['filtros']) {
            $hoja->setCellValue("A{$fila}", 'Sin filtros (todas las actividades aprobadas)');
        }
        $hoja->getColumnDimension('A')->setWidth(44);
        $hoja->getColumnDimension('B')->setWidth(60);

        foreach ($r['desglose'] as $clave => $filas) {
            if (!$filas) {
                continue;
            }
            $titulo = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $etiquetas[$clave] ?? $clave), 0, 28);
            $h = $libro->createSheet()->setTitle($titulo);
            $h->fromArray([['Valor', 'Personas', '% del total']], null, 'A1');
            $h->getStyle('A1:C1')->getFont()->setBold(true);
            foreach ($filas as $i => $f) {
                $h->setCellValue('A'.($i + 2), $this->sinFormula((string) $f['valor']));
                $h->setCellValue('B'.($i + 2), $f['total']);
                $h->setCellValue('C'.($i + 2), $r['total_personas'] > 0 ? round($f['total'] * 100 / $r['total_personas'], 1) : 0);
            }
            $h->getColumnDimension('A')->setWidth(60);
            $h->getColumnDimension('B')->setWidth(12);
            $h->getColumnDimension('C')->setWidth(12);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'estvar_');
        (new Xlsx($libro))->save($tmp);
        $contenido = file_get_contents($tmp);
        unlink($tmp);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="estadisticas-variables-'.now()->format('Ymd-His').'.xlsx"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    // ── Consulta y filtros ────────────────────────────────────────────────────

    /** Expresión SQL del valor efectivo de una columna de Parte 2 (propio del ciudadano o general). */
    private function expresionParte2(string $columna): string
    {
        // $columna sale de las constantes de esta clase, nunca de la petición.
        return "IF(sa.id IS NOT NULL, sa.{$columna}, sg.{$columna})";
    }

    private function consultaBase(Request $request)
    {
        $q = DB::table('caracterizacion_asistentes as a')
            ->join('caracterizacion_actividades as act', 'act.id', '=', 'a.actividad_id')
            ->leftJoin('caracterizacion_seguimiento_asistentes as sa', 'sa.asistente_id', '=', 'a.id')
            ->leftJoin('caracterizacion_seguimientos as sg', 'sg.actividad_id', '=', 'a.actividad_id')
            ->where('act.estado_aprobacion', $request->filled('estado_aprobacion') ? (string) $request->input('estado_aprobacion') : 'aprobada');

        if ($request->filled('secretaria_id')) {
            $q->where('act.secretaria_id', $request->integer('secretaria_id'));
        }
        if ($request->filled('dependencia_id')) {
            $ids = array_filter(array_map('intval', explode(',', (string) $request->input('dependencia_id'))));
            $q->whereIn('act.dependencia_id', $ids);
        }
        if ($request->filled('fecha_desde')) {
            $q->whereDate('act.fecha', '>=', $request->date('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $q->whereDate('act.fecha', '<=', $request->date('fecha_hasta'));
        }
        if ($request->filled('edad_min')) {
            $q->where('a.item15_edad', '>=', $request->integer('edad_min'));
        }
        if ($request->filled('edad_max')) {
            $q->where('a.item15_edad', '<=', $request->integer('edad_max'));
        }

        foreach ($this->filtrosRecibidos($request) as $clave => $valores) {
            if (isset(self::VARIABLES_PARTE1[$clave])) {
                $q->whereIn('a.'.self::VARIABLES_PARTE1[$clave][0], $valores);
            } elseif (isset(self::VARIABLES_PIVOTE[$clave])) {
                [$tabla] = self::VARIABLES_PIVOTE[$clave];
                $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from("{$tabla} as pf")
                    ->whereColumn('pf.asistente_id', 'a.id')->whereIn('pf.valor', $valores));
            } elseif ($clave === 'grupo_etareo') {
                $q->where(function ($g) use ($valores) {
                    foreach ($valores as $nombre) {
                        [$min, $max] = self::GRUPOS_ETAREOS[$nombre];
                        $g->orWhereBetween('a.item15_edad', [max($min, 1), $max]);
                    }
                });
            } elseif (isset(self::VARIABLES_PARTE2[$clave])) {
                $q->whereIn(DB::raw($this->expresionParte2(self::VARIABLES_PARTE2[$clave][0])), $valores);
            }
        }

        return $q;
    }

    /**
     * Variables elegidas en la petición, ya limpias: solo claves conocidas, valores como texto,
     * y grupos etáreos únicamente de la lista fija. Formato: genero[]=Mujer&genero[]=Hombre.
     *
     * @return array<string, string[]>
     */
    private function filtrosRecibidos(Request $request): array
    {
        $conocidas = array_merge(
            array_keys(self::VARIABLES_PARTE1), array_keys(self::VARIABLES_PIVOTE),
            ['grupo_etareo'], array_keys(self::VARIABLES_PARTE2)
        );

        $filtros = [];
        foreach ($conocidas as $clave) {
            $valores = array_values(array_filter(
                array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) $request->input($clave, [])),
                fn ($v) => $v !== ''
            ));
            if ($clave === 'grupo_etareo') {
                $valores = array_values(array_filter($valores, fn ($v) => isset(self::GRUPOS_ETAREOS[$v])));
            }
            if ($valores) {
                $filtros[$clave] = $valores;
            }
        }

        return $filtros;
    }

    private function etiquetas(): array
    {
        $e = ['grupo_etareo' => 'Grupo etáreo (15)'];
        foreach (self::VARIABLES_PARTE1 as $k => $v) {
            $e[$k] = $v[1];
        }
        foreach (self::VARIABLES_PIVOTE as $k => $v) {
            $e[$k] = $v[1];
        }
        foreach (self::VARIABLES_PARTE2 as $k => $v) {
            $e[$k] = $v[1];
        }

        return $e;
    }

    private function sinFormula(?string $valor): ?string
    {
        return ($valor !== null && preg_match('/^[=+\-@\t\r]/', $valor)) ? "'".$valor : $valor;
    }
}
