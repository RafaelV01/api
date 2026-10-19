<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EstadisticasController extends Controller
{
    /**
     * Cruce municipio × {zona, género, étnico, sector-organización, enfoque-diferencial,
     * grupos-etáreos} + total, replicando la forma de las hojas ACTIVIDAD/PROYECTO del
     * Excel fuente — calculado en vivo por SQL, no mantenido a mano.
     *
     * Filtros: secretaria_id, dependencia_id, usuario_id (creador), fecha_desde, fecha_hasta, municipio.
     */
    public function index(Request $request)
    {
        return response()->json($this->calcular($request));
    }

    private function calcular(Request $request): array
    {
        $filtrar = function ($query) use ($request) {
            $query->join('caracterizacion_actividades', 'caracterizacion_actividades.id', '=', 'caracterizacion_asistentes.actividad_id');

            // Por defecto solo cuentan actividades ya APROBADAS: antes de este filtro,
            // una actividad recién subida (pendiente) o incluso rechazada por el
            // administrador ya sumaba en el dashboard como si fuera dato oficial,
            // justo lo que el flujo de aprobación existe para evitar. Se permite
            // overridear con ?estado_aprobacion= para el caso puntual en que un
            // admin quiera revisar pendientes/rechazadas.
            $query->where(
                'caracterizacion_actividades.estado_aprobacion',
                $request->filled('estado_aprobacion') ? $request->string('estado_aprobacion') : 'aprobada'
            );

            if ($request->filled('secretaria_id')) {
                $query->where('caracterizacion_actividades.secretaria_id', $request->integer('secretaria_id'));
            }
            if ($request->filled('dependencia_id')) {
                // Admite uno o varios ids separados por coma (ej. "5,8,12") para el
                // filtro múltiple del frontend, sin dejar de aceptar un solo id.
                $ids = array_filter(explode(',', $request->string('dependencia_id')), fn ($id) => $id !== '');
                $query->whereIn('caracterizacion_actividades.dependencia_id', $ids);
            }
            if ($request->filled('usuario_id')) {
                $query->where('caracterizacion_actividades.creador_id', $request->integer('usuario_id'));
            }
            if ($request->filled('fecha_desde')) {
                $query->whereDate('caracterizacion_actividades.fecha', '>=', $request->date('fecha_desde'));
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('caracterizacion_actividades.fecha', '<=', $request->date('fecha_hasta'));
            }
            if ($request->filled('municipio')) {
                $query->where('caracterizacion_asistentes.item5_municipio', $request->string('municipio'));
            }

            return $query;
        };

        // Una persona que participa en varias reuniones cuenta UNA sola vez: para los datos del ciudadano
        // (zona, género, edad, …) se usa su participación más reciente dentro de los filtros. Lo que depende
        // de cada reunión (mes, secretaría, actividades) cuenta personas distintas dentro de cada grupo.
        $clavePersona = "CONCAT(UPPER(TRIM(caracterizacion_asistentes.item2_tipo_documento)), '|', TRIM(caracterizacion_asistentes.item3_numero_documento))";
        $idsPersonas = $filtrar(DB::table('caracterizacion_asistentes'))
            ->selectRaw('MAX(caracterizacion_asistentes.id) as id')
            ->groupByRaw($clavePersona)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $aplicarFiltros = function ($query) use ($filtrar, $idsPersonas) {
            $filtrar($query);

            return $idsPersonas ? $query->whereIn('caracterizacion_asistentes.id', $idsPersonas) : $query->whereRaw('1 = 0');
        };
        $participaciones = (int) $filtrar(DB::table('caracterizacion_asistentes'))->count('caracterizacion_asistentes.id');

        $principal = $aplicarFiltros(DB::table('caracterizacion_asistentes'))
            ->select('caracterizacion_asistentes.item5_municipio as municipio')
            ->selectRaw("SUM(item6_zona = 'urbana') as zona_urbana")
            ->selectRaw("SUM(item6_zona = 'rural') as zona_rural")
            ->selectRaw("SUM(item9_genero = 'Mujer') as genero_mujer")
            ->selectRaw("SUM(item9_genero = 'Hombre') as genero_hombre")
            ->selectRaw("SUM(item9_genero = 'No Binario') as genero_no_binario")
            ->selectRaw("SUM(item9_genero = 'Transgénero') as genero_transgenero")
            ->selectRaw("SUM(item9_genero = 'LGBTIQ+ OSIGD') as genero_lgbtiq")
            ->selectRaw("SUM(item10_etnico = 'Indígenas') as etnico_indigenas")
            ->selectRaw("SUM(item10_etnico = 'Comun. Negras - Afro- Raizales - Palenqueras') as etnico_negras_afro")
            ->selectRaw("SUM(item10_etnico = 'Pueblo Rom o Gitano') as etnico_rom")
            ->selectRaw("SUM(item13_clasificacion_organizacion = 'Con Ánimo de Lucro') as organizacion_con_animo_lucro")
            ->selectRaw("SUM(item13_clasificacion_organizacion = 'Sin Ánimo de Lucro') as organizacion_sin_animo_lucro")
            // item25_grupo_etareo ya no lo mantiene la app (Parte 2 pasó a ser por actividad, no
            // por ciudadano — ver CaracterizacionSeguimiento): se recalcula aquí directamente a
            // partir de item15_edad, con los mismos rangos de edad de
            // CaracterizacionAsistente::calcularGrupoEtareo().
            ->selectRaw('SUM(item15_edad BETWEEN 1 AND 5) as grupo_primera_infancia')
            ->selectRaw('SUM(item15_edad BETWEEN 6 AND 11) as grupo_infancia')
            ->selectRaw('SUM(item15_edad BETWEEN 12 AND 17) as grupo_adolescencia')
            ->selectRaw('SUM(item15_edad BETWEEN 18 AND 28) as grupo_jovenes')
            ->selectRaw('SUM(item15_edad BETWEEN 29 AND 60) as grupo_adultos')
            ->selectRaw('SUM(item15_edad BETWEEN 61 AND 120) as grupo_mayores')
            ->selectRaw('COUNT(*) as total_poblacion_atendida')
            ->groupBy('caracterizacion_asistentes.item5_municipio')
            ->get()
            ->keyBy('municipio');

        $enfoques = $aplicarFiltros(
                DB::table('caracterizacion_asistente_enfoques')
                    ->join('caracterizacion_asistentes', 'caracterizacion_asistentes.id', '=', 'caracterizacion_asistente_enfoques.asistente_id')
            )
            ->select('caracterizacion_asistentes.item5_municipio as municipio', 'caracterizacion_asistente_enfoques.valor')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('caracterizacion_asistentes.item5_municipio', 'caracterizacion_asistente_enfoques.valor')
            ->get()
            ->groupBy('municipio');

        $resultado = $principal->map(function ($fila) use ($enfoques) {
            $porMunicipio = $enfoques->get($fila->municipio, collect());

            $enfoqueDe = fn (string $valor) => (int) optional($porMunicipio->firstWhere('valor', $valor))->total;

            return [
                'municipio' => $fila->municipio,
                'zona' => ['urbana' => (int) $fila->zona_urbana, 'rural' => (int) $fila->zona_rural],
                'genero' => [
                    'mujer' => (int) $fila->genero_mujer,
                    'hombre' => (int) $fila->genero_hombre,
                    'no_binario' => (int) $fila->genero_no_binario,
                    'transgenero' => (int) $fila->genero_transgenero,
                    'lgbtiq_osigd' => (int) $fila->genero_lgbtiq,
                ],
                'etnico' => [
                    'indigenas' => (int) $fila->etnico_indigenas,
                    'negras_afro_raizales_palenqueras' => (int) $fila->etnico_negras_afro,
                    'pueblo_rom' => (int) $fila->etnico_rom,
                ],
                'sector_organizacion' => [
                    'con_animo_lucro' => (int) $fila->organizacion_con_animo_lucro,
                    'sin_animo_lucro' => (int) $fila->organizacion_sin_animo_lucro,
                ],
                'enfoque_diferencial' => [
                    'discapacidad' => $enfoqueDe('Discapacidad'),
                    'cabeza_de_hogar' => $enfoqueDe('Cabeza de Hogar'),
                    'victimas_de_conflicto' => $enfoqueDe('Víctimas de Conflicto'),
                    'habitante_de_la_calle' => $enfoqueDe('Habitante de la calle'),
                ],
                'grupos_etareos' => [
                    'primera_infancia' => (int) $fila->grupo_primera_infancia,
                    'infancia' => (int) $fila->grupo_infancia,
                    'adolescencia' => (int) $fila->grupo_adolescencia,
                    'jovenes' => (int) $fila->grupo_jovenes,
                    'adultos' => (int) $fila->grupo_adultos,
                    'mayores' => (int) $fila->grupo_mayores,
                ],
                'total_poblacion_atendida' => (int) $fila->total_poblacion_atendida,
            ];
        })->values();

        // Indicadores extra para el dashboard (mismos filtros que la tabla principal).
        $actividades = (int) ($filtrar(DB::table('caracterizacion_asistentes'))
            ->selectRaw('COUNT(DISTINCT caracterizacion_asistentes.actividad_id) as n')->value('n') ?? 0);

        $porMes = $filtrar(DB::table('caracterizacion_asistentes'))
            ->selectRaw("DATE_FORMAT(caracterizacion_actividades.fecha, '%Y-%m') as mes")
            ->selectRaw("COUNT(DISTINCT {$clavePersona}) as total")
            ->selectRaw('COUNT(DISTINCT caracterizacion_asistentes.actividad_id) as actividades')
            ->groupBy('mes')->orderBy('mes')->get()
            ->map(fn ($r) => ['mes' => $r->mes, 'total' => (int) $r->total, 'actividades' => (int) $r->actividades])->values();

        $porSecretaria = $filtrar(DB::table('caracterizacion_asistentes'))
            ->join('caracterizacion_secretarias', 'caracterizacion_secretarias.id', '=', 'caracterizacion_actividades.secretaria_id')
            ->select('caracterizacion_secretarias.nombre as secretaria')
            ->selectRaw("COUNT(DISTINCT {$clavePersona}) as total")
            ->groupBy('caracterizacion_secretarias.nombre')->orderByDesc('total')->get()
            ->map(fn ($r) => ['secretaria' => $r->secretaria, 'total' => (int) $r->total])->values();

        $porSector = $aplicarFiltros(DB::table('caracterizacion_asistentes'))
            ->whereNotNull('caracterizacion_asistentes.item12_sector_organizacion')
            ->select('caracterizacion_asistentes.item12_sector_organizacion as sector')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('caracterizacion_asistentes.item12_sector_organizacion')->orderByDesc('total')->get()
            ->map(fn ($r) => ['sector' => $r->sector, 'total' => (int) $r->total])->values();

        return [
            'por_municipio' => $resultado,
            'total_general' => $resultado->sum('total_poblacion_atendida'),
            'total_participaciones' => $participaciones,
            'total_actividades' => $actividades,
            'total_municipios' => $resultado->count(),
            'por_mes' => $porMes,
            'por_secretaria' => $porSecretaria,
            'por_sector' => $porSector,
        ];
    }

    private const ETIQUETAS = [
        'zona' => ['urbana' => 'Urbana', 'rural' => 'Rural'],
        'genero' => ['mujer' => 'Mujer', 'hombre' => 'Hombre', 'no_binario' => 'No Binario', 'transgenero' => 'Transgénero', 'lgbtiq_osigd' => 'LGBTIQ+ OSIGD'],
        'etnico' => ['indigenas' => 'Indígenas', 'negras_afro_raizales_palenqueras' => 'Negras/Afro/Raizales/Palenqueras', 'pueblo_rom' => 'Pueblo Rom'],
        'sector_organizacion' => ['con_animo_lucro' => 'Con Ánimo de Lucro', 'sin_animo_lucro' => 'Sin Ánimo de Lucro'],
        'enfoque_diferencial' => ['discapacidad' => 'Discapacidad', 'cabeza_de_hogar' => 'Cabeza de Hogar', 'victimas_de_conflicto' => 'Víctimas de Conflicto', 'habitante_de_la_calle' => 'Habitante de la calle'],
        'grupos_etareos' => ['primera_infancia' => 'Primera Infancia', 'infancia' => 'Infancia', 'adolescencia' => 'Adolescencia', 'jovenes' => 'Jóvenes', 'adultos' => 'Adultos', 'mayores' => 'Mayores'],
    ];

    private const TITULOS = [
        'zona' => 'Zona', 'genero' => 'Enfoque de Género', 'etnico' => 'Enfoque Étnico',
        'sector_organizacion' => 'Clasificación de la Organización',
        'enfoque_diferencial' => 'Enfoque Diferencial/Poblacional', 'grupos_etareos' => 'Grupos Etáreos',
    ];

    /**
     * Exporta el mismo cálculo del dashboard (con los mismos filtros) a un .xlsx de dos hojas:
     * "Resumen" (indicadores y consolidado por variable) y "Por municipio" (cruce completo).
     */
    public function exportar(Request $request)
    {
        $d = $this->calcular($request);
        $filas = collect($d['por_municipio']);
        // Texto libre que escribió un ciudadano (municipio/sector): se neutraliza una posible
        // fórmula inyectada antes de entrar a la celda (mismo criterio que ActividadController).
        $seguro = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Resumen');
        $fila = 1;
        $negrita = fn ($rango) => $hoja->getStyle($rango)->getFont()->setBold(true);
        $encabezado = function ($rango) use ($hoja) {
            $hoja->getStyle($rango)->getFont()->setBold(true);
            $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        };

        $hoja->setCellValue('A1', 'Estadísticas — Caracterización de Ciudadanía (FO-PDD-19)');
        $negrita('A1');
        $hoja->getStyle('A1')->getFont()->setSize(14);
        $fila = 2;
        $hoja->setCellValue("A{$fila}", 'Generado: '.now()->format('d/m/Y H:i'));
        $fila += 2;

        foreach ([
            ['Personas caracterizadas', $d['total_general']],
            ['Actividades aprobadas incluidas', $d['total_actividades']],
            ['Municipios con registros', $d['total_municipios']],
        ] as [$etiqueta, $valor]) {
            $hoja->setCellValue("A{$fila}", $etiqueta);
            $hoja->setCellValue("B{$fila}", $valor);
            $negrita("A{$fila}");
            $fila++;
        }
        $fila++;

        $tabla = function (string $titulo, array $filasTabla) use ($hoja, &$fila, $encabezado) {
            $hoja->setCellValue("A{$fila}", $titulo);
            $hoja->setCellValue("B{$fila}", 'Total');
            $encabezado("A{$fila}:B{$fila}");
            $fila++;
            foreach ($filasTabla as [$etiqueta, $total]) {
                $hoja->setCellValue("A{$fila}", $etiqueta);
                $hoja->setCellValue("B{$fila}", $total);
                $fila++;
            }
            $fila++;
        };

        foreach (self::ETIQUETAS as $grupo => $etiquetas) {
            $tabla(self::TITULOS[$grupo], collect($etiquetas)->map(
                fn ($etiqueta, $clave) => [$etiqueta, (int) $filas->sum(fn ($f) => $f[$grupo][$clave] ?? 0)]
            )->values()->all());
        }

        $tabla('Personas por mes', collect($d['por_mes'])->map(fn ($m) => [$m['mes'], $m['total']])->all());
        $tabla('Personas por Secretaría', collect($d['por_secretaria'])->map(fn ($m) => [$seguro($m['secretaria']), $m['total']])->all());
        $tabla('Organizaciones por Secretaría/Oficina (sector)', collect($d['por_sector'])->map(fn ($m) => [$seguro($m['sector']), $m['total']])->all());
        $hoja->getColumnDimension('A')->setWidth(48);
        $hoja->getColumnDimension('B')->setWidth(14);

        // Hoja 2: cruce completo por municipio
        $h2 = $libro->createSheet()->setTitle('Por municipio');
        $cabecera = ['Municipio'];
        foreach (self::ETIQUETAS as $grupo => $etiquetas) {
            foreach ($etiquetas as $etiqueta) {
                $cabecera[] = self::TITULOS[$grupo].' — '.$etiqueta;
            }
        }
        $cabecera[] = 'Total';
        $h2->fromArray($cabecera, null, 'A1');
        $ultimaCol = Coordinate::stringFromColumnIndex(count($cabecera));
        $h2->getStyle("A1:{$ultimaCol}1")->getFont()->setBold(true);
        $h2->getStyle("A1:{$ultimaCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $h2->getStyle("A1:{$ultimaCol}1")->getAlignment()->setWrapText(true);
        $r = 2;
        foreach ($filas as $f) {
            $linea = [$seguro($f['municipio'])];
            foreach (self::ETIQUETAS as $grupo => $etiquetas) {
                foreach (array_keys($etiquetas) as $clave) {
                    $linea[] = $f[$grupo][$clave] ?? 0;
                }
            }
            $linea[] = $f['total_poblacion_atendida'];
            $h2->fromArray($linea, null, "A{$r}");
            $r++;
        }
        $totales = ['TOTAL'];
        foreach (self::ETIQUETAS as $grupo => $etiquetas) {
            foreach (array_keys($etiquetas) as $clave) {
                $totales[] = (int) $filas->sum(fn ($f) => $f[$grupo][$clave] ?? 0);
            }
        }
        $totales[] = $d['total_general'];
        $h2->fromArray($totales, null, "A{$r}");
        $h2->getStyle("A{$r}:{$ultimaCol}{$r}")->getFont()->setBold(true);
        $h2->getColumnDimension('A')->setWidth(26);
        $h2->freezePane('B2');
        $libro->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'estad_').'.xlsx';
        (new Xlsx($libro))->save($tmp);

        return response()->download($tmp, 'estadisticas-caracterizacion-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
