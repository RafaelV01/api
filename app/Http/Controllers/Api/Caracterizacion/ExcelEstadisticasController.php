<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * «Estadísticas por variables» pero SOLO con los archivos Excel subidos (caracterizacion_carga_registros): no toca ni lee las reuniones del
 * sistema. Misma forma de respuesta que EstadisticasVariablesController para reutilizar el panel. Cada persona (documento) cuenta una sola vez
 * aunque esté en varios archivos o en varias filas; sus bienes y servicios de cada fila sí se cuentan.
 */
class ExcelEstadisticasController extends Controller
{
    private const GRUPOS_ETAREOS = [
        'Primera infancia' => [0, 5], 'Infancia' => [6, 11], 'Adolescencia' => [12, 17], 'Jóvenes' => [18, 28], 'Adultos' => [29, 60], 'Mayores' => [61, 120],
    ];

    /** clave → [columna, etiqueta, parte]. */
    private const SIMPLES = [
        'zona' => ['zona', 'Zona (6)', 1],
        'genero' => ['genero', 'Enfoque de género (9)', 1],
        'etnico' => ['etnico', 'Enfoque étnico (10)', 1],
        'sector' => ['sector', 'Sector de la organización (12)', 1],
        'clasificacion' => ['clasificacion', 'Clasificación de la organización (13)', 1],
        'municipio' => ['municipio', 'Municipio (5)', 1],
        'categoria' => ['item21_categoria', 'Beneficio — Categoría (21.1)', 2],
        'bien_servicio' => ['item21_bien_servicio', 'Beneficio — Bien y/o servicio (21.2)', 2],
        'gestion_inversion' => ['item24_gestion_inversion', 'Gestión / Inversión (24)', 2],
        'sector_plan' => ['item26_sector', 'Plan de Desarrollo — Sector (26.1)', 2],
        'programa' => ['item26_programa', 'Plan de Desarrollo — Programa (26.2)', 2],
        'meta_producto' => ['item26_meta_producto', 'Plan de Desarrollo — Meta producto (26.3)', 2],
        'proyecto' => ['item27_nombre_proyecto', 'Proyecto de inversión (27)', 2],
        'ods' => ['item28_ods', 'ODS (28.1)', 2],
        'politica_publica' => ['item29_politica_publica', 'Política pública (29)', 2],
    ];

    /** Variables de varias opciones por persona: clave → [columna, etiqueta]. */
    private const MULTIPLES = [
        'problematica' => ['problematicas', 'Problemática a atender (11)'],
        'enfoque' => ['enfoques', 'Enfoque diferencial / poblacional (14)'],
    ];

    public function variables(Request $request)
    {
        $grupos = [];
        $base = $this->base($request, false);
        $opciones = fn (string $col) => (clone $base)->whereNotNull("r.{$col}")->where("r.{$col}", '!=', '')->distinct()->orderBy("r.{$col}")->limit(300)->pluck("r.{$col}")->all();

        foreach (self::SIMPLES as $clave => [$col, $etiqueta, $parte]) {
            $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'opciones' => $opciones($col), 'parte' => $parte];
        }
        foreach (self::MULTIPLES as $clave => [$col, $etiqueta]) {
            $valores = [];
            foreach ((clone $base)->whereNotNull("r.{$col}")->distinct()->pluck("r.{$col}") as $cadena) {
                foreach (array_filter(explode('|', $cadena)) as $v) {
                    $valores[$v] = true;
                }
            }
            ksort($valores);
            $grupos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'opciones' => array_keys($valores), 'parte' => 1];
        }
        $grupos[] = ['clave' => 'grupo_etareo', 'etiqueta' => 'Grupo etáreo (15)', 'opciones' => array_keys(self::GRUPOS_ETAREOS), 'parte' => 1];

        return response()->json($grupos);
    }

    public function porVariables(Request $request)
    {
        return response()->json($this->calcular($request));
    }

    public function exportar(Request $request)
    {
        $r = $this->calcular($request);
        $etiquetas = $this->etiquetas();

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Resumen');
        $hoja->setCellValue('A1', 'Estadísticas por variables — Archivos Excel');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $fila = 3;
        foreach ([['Personas (únicas)', $r['total_personas']], ['Filas (participaciones)', $r['total_participaciones']], ['Archivos', $r['total_actividades']], ['Personas con seguimiento (Parte 2)', $r['personas_con_seguimiento']]] as $linea) {
            $hoja->fromArray($linea, null, "A{$fila}");
            $fila++;
        }
        $fila++;
        $hoja->setCellValue("A{$fila}", 'Filtros aplicados');
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
        $fila++;
        foreach ($r['filtros'] as $clave => $valores) {
            $hoja->setCellValue("A{$fila}", $this->sinFormula($etiquetas[$clave] ?? $clave));
            $hoja->setCellValue("B{$fila}", $this->sinFormula(implode(', ', $valores)));
            $fila++;
        }
        $hoja->getColumnDimension('A')->setWidth(44);
        $hoja->getColumnDimension('B')->setWidth(60);

        foreach ($r['desglose'] as $clave => $filas) {
            if (! $filas) {
                continue;
            }
            $h = $libro->createSheet()->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $etiquetas[$clave] ?? $clave), 0, 28));
            $h->fromArray([['Valor', 'Personas únicas', '% de las personas']], null, 'A1');
            $h->getStyle('A1:C1')->getFont()->setBold(true);
            foreach ($filas as $i => $f) {
                $h->setCellValue('A'.($i + 2), $this->sinFormula((string) $f['valor']));
                $h->setCellValue('B'.($i + 2), $f['total']);
                $h->setCellValue('C'.($i + 2), $r['total_personas'] > 0 ? round($f['total'] * 100 / $r['total_personas'], 1) : 0);
            }
            $h->getColumnDimension('A')->setWidth(60);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'estxls_');
        (new Xlsx($libro))->save($tmp);
        $contenido = file_get_contents($tmp);
        unlink($tmp);

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="estadisticas-excel-'.now()->format('Ymd-His').'.xlsx"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function calcular(Request $request): array
    {
        $filtros = $this->filtros($request);
        $base = $this->base($request);

        $participaciones = (int) (clone $base)->count('r.id');
        $archivos = (int) (clone $base)->selectRaw('COUNT(DISTINCT r.carga_id) as n')->value('n');
        $conSeguimiento = (int) (clone $base)->where(fn ($q) => $q->whereNotNull('r.item21_bien_servicio')->orWhereNotNull('r.item26_programa')->orWhereNotNull('r.item26_meta_producto')->orWhereNotNull('r.item21_categoria'))
            ->selectRaw('COUNT(DISTINCT r.persona_clave) as n')->value('n');

        // Datos personales: la fila MÁS RECIENTE de cada persona dentro de los filtros (así cada persona cuenta una sola vez)
        $ultimos = (clone $base)->selectRaw('MAX(r.id)')->groupBy('r.persona_clave');
        $total = (int) DB::query()->fromSub($ultimos, 'u')->count();
        $personas = fn () => DB::table('caracterizacion_carga_registros as r')->whereIn('r.id', (clone $ultimos));

        $desglose = [];
        foreach (self::SIMPLES as $clave => [$col, , $parte]) {
            if ($parte === 1) {
                $desglose[$clave] = $personas()->whereNotNull("r.{$col}")->where("r.{$col}", '!=', '')
                    ->when(isset($filtros[$clave]), fn ($q) => $q->whereIn("r.{$col}", $filtros[$clave]))
                    ->select("r.{$col} as valor")->selectRaw('COUNT(*) as total')->groupBy("r.{$col}")->orderByDesc('total')->get()
                    ->map(fn ($f) => ['valor' => $f->valor, 'total' => (int) $f->total])->all();
            }
        }

        foreach (self::MULTIPLES as $clave => [$col]) {
            $cuentas = [];
            foreach ($personas()->whereNotNull("r.{$col}")->pluck("r.{$col}") as $cadena) {
                foreach (array_filter(explode('|', $cadena)) as $v) {
                    if (! isset($filtros[$clave]) || in_array($v, $filtros[$clave], true)) {
                        $cuentas[$v] = ($cuentas[$v] ?? 0) + 1;
                    }
                }
            }
            arsort($cuentas);
            $desglose[$clave] = collect($cuentas)->map(fn ($n, $v) => ['valor' => (string) $v, 'total' => $n])->values()->all();
        }

        $desglose['grupo_etareo'] = [];
        foreach (self::GRUPOS_ETAREOS as $nombre => [$min, $max]) {
            if (isset($filtros['grupo_etareo']) && ! in_array($nombre, $filtros['grupo_etareo'], true)) {
                continue;
            }
            $n = $personas()->whereBetween('r.edad', [max($min, 1), $max])->count('r.id');
            if ($n > 0) {
                $desglose['grupo_etareo'][] = ['valor' => $nombre, 'total' => $n];
            }
        }

        // Beneficio y plan de desarrollo: dependen de CADA fila (una persona puede recibir cosas distintas en cada archivo/reunión)
        foreach (self::SIMPLES as $clave => [$col, , $parte]) {
            if ($parte === 2) {
                $desglose[$clave] = (clone $base)->whereNotNull("r.{$col}")->where("r.{$col}", '!=', '')
                    ->when(isset($filtros[$clave]), fn ($q) => $q->whereIn("r.{$col}", $filtros[$clave]))
                    ->select("r.{$col} as valor")->selectRaw('COUNT(DISTINCT r.persona_clave) as total')->groupBy("r.{$col}")->orderByDesc('total')->limit(50)->get()
                    ->map(fn ($f) => ['valor' => $f->valor, 'total' => (int) $f->total])->all();
            }
        }

        return [
            'total_personas' => $total,
            'total_participaciones' => $participaciones,
            'total_actividades' => $archivos, // aquí: archivos incluidos
            'personas_con_seguimiento' => $conSeguimiento,
            'desglose' => $desglose,
            'filtros' => $filtros,
        ];
    }

    /** Consulta base: solo registros de los archivos elegidos (`cargas` = ids separados por coma; vacío = todos) y los filtros de variables. */
    private function base(Request $request, bool $conFiltros = true)
    {
        $q = DB::table('caracterizacion_carga_registros as r');
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('cargas'))));
        if ($ids) {
            $q->whereIn('r.carga_id', $ids);
        }
        if (! $conFiltros) {
            return $q;
        }

        foreach ($this->filtros($request) as $clave => $valores) {
            if (isset(self::SIMPLES[$clave])) {
                $q->whereIn('r.'.self::SIMPLES[$clave][0], $valores);
            } elseif (isset(self::MULTIPLES[$clave])) {
                $col = 'r.'.self::MULTIPLES[$clave][0];
                $q->where(function ($g) use ($col, $valores) {
                    foreach ($valores as $v) {
                        $g->orWhere($col, 'like', '%|'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v).'|%');
                    }
                });
            } elseif ($clave === 'grupo_etareo') {
                $q->where(function ($g) use ($valores) {
                    foreach ($valores as $nombre) {
                        [$min, $max] = self::GRUPOS_ETAREOS[$nombre];
                        $g->orWhereBetween('r.edad', [max($min, 1), $max]);
                    }
                });
            }
        }

        return $q;
    }

    /** Filtros recibidos como clave[]=valor, solo de variables conocidas y con valores de texto razonables. */
    private function filtros(Request $request): array
    {
        $conocidas = array_merge(array_keys(self::SIMPLES), array_keys(self::MULTIPLES), ['grupo_etareo']);
        $filtros = [];
        foreach ($conocidas as $clave) {
            $valores = $request->input($clave);
            if (! is_array($valores)) {
                continue;
            }
            $valores = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 255) : null, $valores), fn ($v) => $v !== null && $v !== '')));
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
        foreach (self::SIMPLES as $clave => [, $etiqueta]) {
            $e[$clave] = $etiqueta;
        }
        foreach (self::MULTIPLES as $clave => [, $etiqueta]) {
            $e[$clave] = $etiqueta;
        }

        return $e;
    }

    private function sinFormula(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
