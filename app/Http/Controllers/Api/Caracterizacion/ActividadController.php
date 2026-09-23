<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAsistente;
use App\Models\CaracterizacionAsistenteEnfoque;
use App\Models\CaracterizacionAsistenteProblematica;
use App\Models\CaracterizacionPerfil;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ActividadController extends Controller
{
    /**
     * Lista actividades visibles para el usuario autenticado según su rol
     * (contratista: solo las propias; secretaría: las de su secretaría; admin: todas).
     */
    public function index(Request $request)
    {
        $query = CaracterizacionActividad::with(['dependencia', 'secretaria', 'creador'])
            ->visiblePara(Auth::user())
            ->withCount('asistentes')
            ->latest();

        if ($request->filled('secretaria_id')) {
            $query->where('secretaria_id', $request->integer('secretaria_id'));
        }
        if ($request->filled('dependencia_id')) {
            $query->where('dependencia_id', $request->integer('dependencia_id'));
        }
        if ($request->filled('creador_id')) {
            $query->where('creador_id', $request->integer('creador_id'));
        }
        if ($request->filled('estado_aprobacion')) {
            $query->where('estado_aprobacion', $request->string('estado_aprobacion'));
        }

        return response()->json($query->get());
    }

    /**
     * Crea una nueva actividad de caracterización. El contratista debe tener un
     * perfil de caracterización activo; dependencia/secretaría se derivan de ese
     * perfil, nunca del cuerpo de la petición (evita que alguien reporte a nombre
     * de otra dependencia).
     */
    public function store(Request $request)
    {
        $perfil = Auth::user()->caracterizacionPerfil;

        if (!$perfil || !$perfil->activo || !$perfil->dependencia_id) {
            return response()->json([
                'message' => 'Tu usuario no tiene una dependencia de caracterización asignada.',
            ], 403);
        }

        $validated = $request->validate([
            'tema' => 'required|string|max:255',
            'fecha' => 'required|date',
            'hora' => 'required|date_format:H:i',
            'quien_dirige_expone' => 'required|string|max:255',
            'firma_expositor_base64' => 'nullable|string',
            'firma_expositor_hash' => 'nullable|string|size:64',
            'municipio' => ['required', 'string', Rule::exists('caracterizacion_opciones', 'valor')->where('categoria', 'municipio')],
            'tipo_evento' => 'required|in:asistencia_tecnica,capacitacion,socializacion,otro',
            'otro_evento_detalle' => 'nullable|string|max:300|required_if:tipo_evento,otro',
        ]);

        $actividad = CaracterizacionActividad::create([
            ...$validated,
            'codigo' => Str::upper(Str::random(8)),
            'slug_acceso' => Str::slug($validated['tema']).'-'.uniqid(),
            'departamento' => 'Casanare',
            'creador_id' => Auth::id(),
            'dependencia_id' => $perfil->dependencia_id,
            'secretaria_id' => $perfil->secretaria_id,
        ]);

        return response()->json($actividad, 201);
    }

    public function show(CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $actividad->load(['dependencia', 'secretaria', 'creador', 'asistentes' => function ($q) {
            $q->with(['problematicas', 'enfoques']);
        }]);

        return response()->json($actividad);
    }

    /**
     * El contratista completa la Parte 2 (ítems 20–32) de una fila ya diligenciada
     * por el ciudadano en la Parte 1.
     */
    public function completarParte2(Request $request, CaracterizacionActividad $actividad, CaracterizacionAsistente $asistente)
    {
        $this->autorizarAcceso($actividad);

        if ($asistente->actividad_id !== $actividad->id) {
            return response()->json(['message' => 'El asistente no pertenece a esta actividad.'], 404);
        }

        $opcion = fn (string $categoria) => Rule::exists('caracterizacion_opciones', 'valor')
            ->where('categoria', $categoria)
            ->where('activo', true);

        $validated = $request->validate([
            'item20_codigo_dane' => 'nullable|string|max:20',
            'item21_categoria' => 'nullable|string|max:150',
            'item21_bien_servicio' => 'nullable|string|max:150',
            'item22_descripcion_beneficio' => 'nullable|string|max:500',
            'item23_fecha_beneficio' => 'nullable|date',
            'item24_gestion_inversion' => 'nullable|in:Gestión,Inversión',
            'item26_sector' => 'nullable|string|max:150',
            'item26_programa' => 'nullable|string|max:150',
            'item26_meta_producto' => 'nullable|string|max:255',
            'item27_nombre_proyecto' => 'nullable|string|max:255',
            'item28_ods' => ['nullable', 'string', $opcion('ods')],
            'item28_ddhh' => ['nullable', 'string', $opcion('ddhh')],
            'item28_pilares_paz' => ['nullable', 'string', $opcion('pilares_paz')],
            'item29_politica_publica' => ['nullable', 'string', $opcion('politica_publica')],
            'item30_politica_mipg' => ['nullable', 'string', $opcion('politica_mipg')],
            'item31_total_beneficiarios' => 'nullable|integer|min:0',
            'item32_acto_tipo' => ['nullable', 'string', $opcion('acto_tipo')],
            'item32_numero' => 'nullable|string|max:50',
            'item32_fecha' => 'nullable|date',
        ]);

        $asistente->fill($validated);
        $asistente->part2_completado_por = Auth::id();
        $asistente->part2_completado_en = now();
        $asistente->save();

        return response()->json($asistente);
    }

    /**
     * Cierra la actividad, impidiendo nuevos autoregistros de ciudadanos.
     */
    public function finalizar(CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $actividad->estado = 'cerrada';
        $actividad->save();

        return response()->json(['message' => 'Actividad finalizada correctamente.', 'actividad' => $actividad]);
    }

    public function generarPdf(CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $actividad->load(['asistentes.problematicas', 'asistentes.enfoques', 'dependencia', 'secretaria']);

        $pdf = Pdf::loadView('pdf.caracterizacion', ['actividad' => $actividad]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="caracterizacion-'.$actividad->codigo.'.pdf"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Exporta la actividad a Excel (.xlsx) usando el archivo institucional FO-PDD-19
     * original como plantilla (resources/templates/FO-PDD-19.xlsx) — se escriben los
     * datos directamente sobre esa copia, así se conservan exactamente el logo, los
     * anchos de columna, los colores, los encabezados fusionados y las listas
     * desplegables del formato real, en vez de recrear el diseño desde cero.
     */
    public function generarExcel(CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $actividad->load(['asistentes.problematicas', 'asistentes.enfoques', 'dependencia', 'secretaria']);

        $templatePath = resource_path('templates/FO-PDD-19.xlsx');
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheetByName('FO-PDD-19');

        // ── Encabezado (TEMA / FECHA / HORA / QUIEN DIRIGE / MUNICIPIO / DEPARTAMENTO / TIPO DE EVENTO) ──
        $sheet->setCellValue('C1', $actividad->tema);
        $sheet->setCellValue('C2', optional($actividad->fecha)->format('d/m/Y'));
        $sheet->setCellValue('Q2', \Carbon\Carbon::parse($actividad->hora)->format('h:i A'));
        $sheet->setCellValue('A3', 'QUIEN DIRIGE Y/O EXPONE: '.$actividad->quien_dirige_expone
            .'          FIRMA: ______________          MUNICIPIO: '.$actividad->municipio);
        $sheet->setCellValue('W3', $actividad->departamento);

        $marcarTipoEvento = function (string $celda, string $valorEsperado) use ($sheet, $actividad) {
            $texto = (string) $sheet->getCell($celda)->getValue();
            if ($actividad->tipo_evento === $valorEsperado) {
                $sheet->setCellValue($celda, 'X  '.$texto);
            }
        };
        $marcarTipoEvento('AI2', 'asistencia_tecnica');
        $marcarTipoEvento('AI3', 'capacitacion');
        $lineasAI4 = explode("\n", (string) $sheet->getCell('AI4')->getValue());
        foreach ($lineasAI4 as $i => $linea) {
            if ($actividad->tipo_evento === 'socializacion' && str_contains($linea, 'Socialización')) {
                $lineasAI4[$i] = 'X  '.$linea;
            }
            if ($actividad->tipo_evento === 'otro' && str_contains($linea, 'Otro')) {
                $lineasAI4[$i] = 'X  '.$linea.' '.$actividad->otro_evento_detalle;
            }
        }
        $sheet->setCellValue('AI4', implode("\n", $lineasAI4));

        // ── Filas de datos (empiezan en la fila 8, igual que en el formato original) ──
        $colsParte1 = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI'];
        $colsParte2 = ['AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA', 'BB', 'BC'];

        $fila = 8;
        $archivosTemporales = [];
        foreach ($actividad->asistentes as $index => $a) {
            $problematicas = $a->problematicas->pluck('valor')->all();
            $enfoques = $a->enfoques->pluck('valor')->all();
            $marca = fn (bool $cond) => $cond ? 'X' : null;

            $valoresParte1 = [
                $index + 1,
                $a->item1_nombre,
                $a->item2_tipo_documento,
                $a->item3_numero_documento,
                $a->item4_cargo_barrio_vereda,
                $a->item5_municipio,
                $marca($a->item6_zona === 'urbana'),
                $marca($a->item6_zona === 'rural'),
                trim($a->item7_ubicacion_tipo.' '.$a->item7_ubicacion_detalle),
                trim($a->item8_contacto_tipo.': '.$a->item8_contacto_valor),
                $marca($a->item9_genero === 'Mujer'),
                $marca($a->item9_genero === 'Hombre'),
                $marca($a->item9_genero === 'No Binario'),
                $marca($a->item9_genero === 'Transgénero'),
                $marca($a->item9_genero === 'LGBTIQ+ OSIGD'),
                $marca($a->item10_etnico === 'Indígenas'),
                $marca($a->item10_etnico === 'Comun. Negras - Afro- Raizales - Palenqueras'),
                $marca($a->item10_etnico === 'Pueblo Rom o Gitano'),
                $marca(in_array('Institucional', $problematicas)),
                $marca(in_array('Ambiental', $problematicas)),
                $marca(in_array('Salud', $problematicas)),
                $marca(in_array('Cultural', $problematicas)),
                $marca(in_array('Educativa', $problematicas)),
                $a->item12_sector_organizacion,
                $marca($a->item13_clasificacion_organizacion === 'Con Ánimo de Lucro'),
                $marca($a->item13_clasificacion_organizacion === 'Sin Ánimo de Lucro'),
                $marca(in_array('Discapacidad', $enfoques)),
                $marca(in_array('Cabeza de Hogar', $enfoques)),
                $marca(in_array('Víctimas de Conflicto', $enfoques)),
                $marca(in_array('Habitante de la calle', $enfoques)),
                $a->item15_edad,
                $a->item16_tamano_grupo_familiar,
                $a->item17_canal_comunicacion,
                $a->item18_idioma_lengua_dialecto,
                null, // firma -> imagen embebida aparte
            ];
            foreach ($colsParte1 as $i => $col) {
                if ($valoresParte1[$i] !== null) {
                    $sheet->setCellValue("{$col}{$fila}", $valoresParte1[$i]);
                }
            }

            if ($a->firma_ciudadano_base64 && str_contains($a->firma_ciudadano_base64, ',')) {
                [, $base64Data] = explode(',', $a->firma_ciudadano_base64, 2);
                $binario = base64_decode($base64Data);
                if ($binario !== false) {
                    $tmpImg = tempnam(sys_get_temp_dir(), 'firma_').'.jpg';
                    file_put_contents($tmpImg, $binario);
                    $archivosTemporales[] = $tmpImg;

                    // Columna AI (~180px de ancho, 25.6328125 caracteres) y fila de datos (39pt = 52px de alto)
                    // en la plantilla original. Se escala la firma tipo "object-fit: contain" (igual que en el
                    // PDF con max-width/max-height) para que quepa sin deformarse sin importar el aspect ratio
                    // con el que el ciudadano firmó, y luego se centra dentro de la celda.
                    $colAiPx = 180;
                    $rowPx = 52;
                    $maxW = $colAiPx - 10;
                    $maxH = $rowPx - 18;

                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Firma');
                    $drawing->setPath($tmpImg);

                    $origW = $drawing->getWidth();
                    $origH = $drawing->getHeight();
                    $scale = min($maxW / max($origW, 1), $maxH / max($origH, 1), 1);
                    $newW = (int) round($origW * $scale);
                    $newH = (int) round($origH * $scale);

                    $drawing->setResizeProportional(false);
                    $drawing->setWidth($newW);
                    $drawing->setHeight($newH);
                    $drawing->setCoordinates("AI{$fila}");
                    $drawing->setOffsetX((int) max(2, round(($colAiPx - $newW) / 2)));
                    $drawing->setOffsetY((int) max(2, round(($rowPx - $newH) / 2)));
                    $drawing->setWorksheet($sheet);
                }
            }

            $valoresParte2 = [
                $a->item20_codigo_dane,
                $a->item21_categoria,
                $a->item21_bien_servicio,
                $a->item22_descripcion_beneficio,
                optional($a->item23_fecha_beneficio)->format('d/m/Y'),
                $a->item24_gestion_inversion,
                $a->item25_grupo_etareo,
                $a->item26_sector,
                $a->item26_programa,
                $a->item26_meta_producto,
                $a->item27_nombre_proyecto,
                $a->item28_ods,
                $a->item28_ddhh,
                $a->item28_pilares_paz,
                $a->item29_politica_publica,
                $a->item30_politica_mipg,
                $a->item31_total_beneficiarios,
                $a->item32_acto_tipo,
                $a->item32_numero,
                optional($a->item32_fecha)->format('d/m/Y'),
            ];
            foreach ($colsParte2 as $i => $col) {
                if ($valoresParte2[$i] !== null) {
                    $sheet->setCellValue("{$col}{$fila}", $valoresParte2[$i]);
                }
            }

            $fila++;
        }

        // Las demás hojas (INSTRUCTIVO, ACTIVIDAD, PROYECTO) del formato original se
        // conservan tal cual — solo se activa la hoja de datos al abrir el archivo.
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'caracterizacion-'.$actividad->codigo.'.xlsx';
        $tmpPath = tempnam(sys_get_temp_dir(), 'caract_xlsx_');
        $writer->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);
        foreach ($archivosTemporales as $tmpImg) {
            @unlink($tmpImg);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Verifica que el usuario autenticado pueda ver/editar esta actividad según
     * su perfil de caracterización (mismo criterio que scopeVisiblePara).
     */
    private function autorizarAcceso(CaracterizacionActividad $actividad): void
    {
        $perfil = Auth::user()->caracterizacionPerfil;

        $autorizado = $perfil && $perfil->activo && (
            $perfil->rol === CaracterizacionPerfil::ROL_ADMINISTRADOR
            || ($perfil->rol === CaracterizacionPerfil::ROL_SECRETARIA && $perfil->secretaria_id === $actividad->secretaria_id)
            || ($perfil->rol === CaracterizacionPerfil::ROL_CONTRATISTA && Auth::id() === $actividad->creador_id)
        );

        abort_unless($autorizado, 403, 'No autorizado para esta actividad.');
    }
}
