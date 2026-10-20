<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAsistente;
use App\Models\CaracterizacionAsistenteEnfoque;
use App\Models\CaracterizacionAsistenteProblematica;
use App\Models\CaracterizacionColaborador;
use App\Models\CaracterizacionPerfil;
use App\Models\CaracterizacionSeguimiento;
use App\Models\CaracterizacionSeguimientoAsistente;
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
    /** Columnas del listado de actividades (todas menos las firmas del expositor). */
    private const COLUMNAS_LISTA = [
        'id', 'codigo', 'slug_acceso', 'tema', 'fecha', 'hora', 'quien_dirige_expone', 'municipio', 'departamento',
        'tipo_evento', 'otro_evento_detalle', 'creador_id', 'dependencia_id', 'secretaria_id', 'estado',
        'estado_aprobacion', 'aprobado_por', 'fecha_aprobacion', 'observacion_aprobacion', 'created_at', 'updated_at',
    ];

    /** Columnas de cada ciudadano en el detalle (sin firma, IP ni navegador: no las usa la pantalla). */
    private const COLUMNAS_ASISTENTE = [
        'id', 'actividad_id', 'item1_nombre', 'item2_tipo_documento', 'item3_numero_documento', 'item4_cargo_barrio_vereda',
        'item5_municipio', 'item6_zona', 'item7_ubicacion_tipo', 'item7_ubicacion_detalle', 'item8_contacto_tipo',
        'item8_contacto_valor', 'item9_genero', 'item10_etnico', 'item12_sector_organizacion',
        'item13_clasificacion_organizacion', 'item15_edad', 'item16_tamano_grupo_familiar',
        'item17_canal_comunicacion', 'item18_idioma_lengua_dialecto', 'created_at', 'updated_at',
    ];

    public function index(Request $request)
    {
        // El listado NO trae las firmas en base64 (pesan decenas de KB por actividad): solo las necesita el PDF/Excel.
        $query = CaracterizacionActividad::query()->select(self::COLUMNAS_LISTA)
            ->with(['dependencia:id,nombre', 'secretaria:id,nombre', 'creador:id,nombre_completo'])
            ->visiblePara(Auth::user())
            ->withCount('asistentes')
            ->orderByDesc('created_at')->orderByDesc('id');

        $this->aplicarFiltros($query, $request);

        // Con `page` o `per_page` responde paginado (lo que usan las pantallas); sin ellos devuelve la lista
        // completa pero liviana, para no romper clientes antiguos.
        if ($request->filled('page') || $request->filled('per_page')) {
            return response()->json($query->paginate(min(max($request->integer('per_page', 20), 5), 100)));
        }

        return response()->json($query->get());
    }

    /**
     * Filtros compartidos por el listado (index) y por el consolidado descargable.
     */
    private function aplicarFiltros($query, Request $request): void
    {
        if ($request->filled('secretaria_id')) {
            $query->where('secretaria_id', $request->integer('secretaria_id'));
        }
        if ($request->filled('dependencia_id')) {
            // Admite uno o varios ids separados por coma (ej. "5,8,12") para el
            // filtro múltiple del frontend, sin dejar de aceptar un solo id.
            $ids = array_filter(explode(',', $request->string('dependencia_id')), fn ($id) => $id !== '');
            $query->whereIn('dependencia_id', $ids);
        }
        if ($request->filled('creador_id')) {
            $query->where('creador_id', $request->integer('creador_id'));
        }
        if ($request->filled('estado_aprobacion')) {
            $query->where('estado_aprobacion', $request->string('estado_aprobacion'));
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }
        if ($request->filled('municipio')) {
            $query->where('municipio', $request->string('municipio'));
        }
        if ($request->filled('tema')) {
            $query->where('tema', 'like', '%'.$request->string('tema').'%');
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->date('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->date('fecha_hasta'));
        }
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
            'quien_dirige_expone' => ['required', 'string', 'max:255', 'regex:/^[\p{L}][\p{L} .,\'\-()]*$/u'],
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

        $actividad->load(['dependencia', 'secretaria', 'creador', 'seguimiento', 'colaboradores.usuario', 'asistentes' => function ($q) {
            $q->select(self::COLUMNAS_ASISTENTE)->with(['problematicas', 'enfoques', 'seguimientoPropio']);
        }]);
        $actividad->makeHidden(['firma_expositor_base64', 'firma_expositor_hash']);

        // La lista de colaboradores (incluye invitaciones pendientes/rechazadas con
        // nombre) es información pensada solo para el creador — el frontend ya la
        // oculta a otros, pero la API no debería entregarla igual a un colaborador
        // aceptado que solo tiene acceso para ayudar con la Parte 2.
        if (Auth::id() !== $actividad->creador_id) {
            $actividad->unsetRelation('colaboradores');
        }

        return response()->json($actividad);
    }

    /**
     * El contratista creador (o un colaborador invitado y aceptado) completa la
     * Parte 2 (ítems 20–32) de la actividad — una sola vez por actividad, no por
     * cada ciudadano registrado en la Parte 1.
     */
    public function completarParte2(Request $request, CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $validated = $request->validate($this->reglasParte2());
        // El código DANE sale del municipio de la actividad, no de lo que escriba el usuario.
        $validated['item20_codigo_dane'] = $this->codigoDane($actividad->municipio, $validated['item20_codigo_dane'] ?? null);

        $datos = [
            ...$validated,
            'completado_por' => Auth::id(),
            'completado_en' => now(),
        ];

        try {
            $seguimiento = CaracterizacionSeguimiento::updateOrCreate(['actividad_id' => $actividad->id], $datos);
        } catch (\Illuminate\Database\QueryException $e) {
            // Colisión rara: el creador y un colaborador guardaron casi al mismo
            // tiempo la primera vez y ambos intentaron crear la fila (unique en
            // actividad_id). Para cuando llegamos aquí la otra ya existe, así que
            // un segundo intento actualiza en vez de chocar de nuevo.
            $seguimiento = CaracterizacionSeguimiento::updateOrCreate(['actividad_id' => $actividad->id], $datos);
        }

        return response()->json($seguimiento);
    }

    /**
     * Reglas de validación de la Parte 2 (ítems 20–32); las usan el seguimiento general de
     * la actividad y el seguimiento propio de cada ciudadano.
     */
    private function reglasParte2(): array
    {
        $opcion = fn (string $categoria) => Rule::exists('caracterizacion_opciones', 'valor')
            ->where('categoria', $categoria)
            ->where('activo', true);

        return [
            'item20_codigo_dane' => 'nullable|string|max:20',
            'item21_categoria' => 'nullable|string|max:150',
            'item21_bien_servicio' => 'nullable|string|max:150',
            'item22_descripcion_beneficio' => 'nullable|string|max:500',
            'item23_fecha_beneficio' => 'nullable|date',
            'item24_gestion_inversion' => 'nullable|in:Gestión,Inversión',
            'item26_sector' => 'nullable|string|max:150',
            'item26_programa' => 'nullable|string|max:255',
            'item26_meta_producto' => 'nullable|string|max:800',
            'item27_nombre_proyecto' => 'nullable|string|max:255',
            'item28_ods' => ['nullable', 'string', $opcion('ods')],
            'item28_ddhh' => ['nullable', 'string', $opcion('ddhh')],
            'item28_pilares_paz' => ['nullable', 'string', $opcion('pilares_paz')],
            'item29_politica_publica' => ['nullable', 'string', $opcion('politica_publica')],
            'item30_politica_mipg' => ['nullable', 'string', $opcion('politica_mipg')],
            'item31_total_beneficiarios' => 'nullable|integer|min:0',
            'item32_acto_tipo' => ['nullable', 'string', $opcion('acto_tipo')],
            'item32_numero' => ['nullable', 'string', 'max:50', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} .\-\/]*$/u'],
            'item32_fecha' => 'nullable|date',
        ];
    }

    /**
     * Código DANE del ítem 20: sale del municipio (no se confía en lo que escriba el usuario).
     * Si el municipio no es de Casanare se conserva el valor enviado, si lo hay.
     */
    private function codigoDane(?string $municipio, ?string $enviado): ?string
    {
        return \App\Support\CodigoDane::deMunicipio($municipio) ?? ($enviado !== '' ? $enviado : null);
    }

    /**
     * Seguimiento (Parte 2) propio de UN ciudadano: sobrescribe al de la actividad solo para él.
     * Mismo permiso que el seguimiento general (creador, colaborador aceptado, secretaría, admin).
     */
    public function completarParte2Asistente(Request $request, CaracterizacionAsistente $asistente)
    {
        $actividad = $asistente->actividad;
        $this->autorizarAcceso($actividad);

        $validated = $request->validate($this->reglasParte2());
        // El código sale del municipio de ESTE ciudadano (cada uno puede vivir en un municipio distinto).
        $validated['item20_codigo_dane'] = $this->codigoDane($asistente->item5_municipio, $validated['item20_codigo_dane'] ?? null);

        $seguimiento = CaracterizacionSeguimientoAsistente::updateOrCreate(
            ['asistente_id' => $asistente->id],
            [...$validated, 'completado_por' => Auth::id(), 'completado_en' => now()]
        );

        return response()->json($seguimiento);
    }

    /**
     * Aplica el MISMO seguimiento (Parte 2) a varios ciudadanos de la actividad de una vez, o se los quita (`quitar`).
     * Cada uno queda con su seguimiento propio; el código DANE sale del municipio de cada persona.
     */
    public function seguimientoLote(Request $request, CaracterizacionActividad $actividad)
    {
        $this->autorizarAcceso($actividad);

        $ids = collect($request->validate([
            'asistentes' => 'required|array|min:1|max:500',
            'asistentes.*' => 'integer',
        ])['asistentes'])->unique()->values();

        $asistentes = $actividad->asistentes()->whereIn('id', $ids)->get(['id', 'actividad_id', 'item5_municipio']);
        if ($asistentes->count() !== $ids->count()) {
            return response()->json(['message' => 'Algún ciudadano no pertenece a esta actividad.'], 422);
        }

        if ($request->boolean('quitar')) {
            DB::transaction(fn () => CaracterizacionSeguimientoAsistente::whereIn('asistente_id', $ids)->get()->each->delete());

            return response()->json(['actualizados' => $asistentes->count(), 'accion' => 'quitado']);
        }

        $validated = $request->validate($this->reglasParte2());
        DB::transaction(function () use ($asistentes, $validated) {
            foreach ($asistentes as $a) {
                CaracterizacionSeguimientoAsistente::updateOrCreate(
                    ['asistente_id' => $a->id],
                    [...$validated, 'item20_codigo_dane' => $this->codigoDane($a->item5_municipio, $validated['item20_codigo_dane'] ?? null), 'completado_por' => Auth::id(), 'completado_en' => now()]
                );
            }
        });

        return response()->json(['actualizados' => $asistentes->count(), 'accion' => 'guardado']);
    }

    /** Quita el seguimiento propio del ciudadano: vuelve a valer el general de la actividad. */
    public function quitarParte2Asistente(CaracterizacionAsistente $asistente)
    {
        $this->autorizarAcceso($asistente->actividad);

        // delete() sobre la instancia para que Auditable registre quién y qué se quitó.
        CaracterizacionSeguimientoAsistente::where('asistente_id', $asistente->id)->get()->each->delete();

        return response()->json(['message' => 'El ciudadano vuelve a usar el seguimiento general de la actividad.']);
    }

    /**
     * El creador de la actividad invita a otro usuario existente a ayudarle a
     * completar la Parte 2. Solo el creador original puede invitar (no se permite
     * que un colaborador ya aceptado invite a más gente, para evitar cadenas de
     * invitaciones sin control).
     */
    public function invitarColaborador(Request $request, CaracterizacionActividad $actividad)
    {
        abort_unless(Auth::id() === $actividad->creador_id, 403, 'Solo el creador de la actividad puede invitar colaboradores.');

        $validated = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id',
        ]);

        if ((int) $validated['usuario_id'] === (int) $actividad->creador_id) {
            return response()->json(['message' => 'No puedes invitarte a ti mismo como colaborador.'], 422);
        }

        $existente = CaracterizacionColaborador::where('actividad_id', $actividad->id)
            ->where('usuario_id', $validated['usuario_id'])
            ->first();

        // Un rechazo previo NO bloquea una reinvitación futura — solo un pendiente o
        // ya aceptado deberían impedir invitar de nuevo. Antes cualquier fila
        // existente (incluida 'rechazado') bloqueaba para siempre, sin que el
        // creador tuviera forma de reintentar.
        if ($existente && $existente->estado !== CaracterizacionColaborador::ESTADO_RECHAZADO) {
            return response()->json([
                'message' => $existente->estado === CaracterizacionColaborador::ESTADO_ACEPTADO
                    ? 'Este usuario ya es colaborador de esta actividad.'
                    : 'Este usuario ya tiene una invitación pendiente para esta actividad.',
                'colaborador' => $existente->load('usuario'),
            ], 422);
        }

        try {
            if ($existente) {
                // Reutiliza la fila rechazada en vez de crear una nueva (la
                // migración tiene unique(actividad_id, usuario_id)).
                $existente->update([
                    'invitado_por' => Auth::id(),
                    'estado' => CaracterizacionColaborador::ESTADO_PENDIENTE,
                    'created_at' => now(),
                    'responded_at' => null,
                ]);
                $colaborador = $existente;
            } else {
                $colaborador = CaracterizacionColaborador::create([
                    'actividad_id' => $actividad->id,
                    'usuario_id' => $validated['usuario_id'],
                    'invitado_por' => Auth::id(),
                    'estado' => CaracterizacionColaborador::ESTADO_PENDIENTE,
                    'created_at' => now(),
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Condición de carrera: otra invitación al mismo usuario se creó entre
            // el chequeo de arriba y este create()/update() — se responde igual que
            // el caso no concurrente, en vez de un 500 con el detalle SQL crudo.
            return response()->json(['message' => 'Este usuario ya fue invitado a esta actividad.'], 422);
        }

        \App\Models\CaracterizacionNotificacion::enviar(
            (int) $validated['usuario_id'], 'invitacion', 'Te invitaron a colaborar',
            (Auth::user()->nombre_completo ?? 'Un compañero').' te invitó a completar el seguimiento de «'.$actividad->tema.'»', '/caracterizacion/compartidas'
        );

        return response()->json($colaborador->load('usuario'), 201);
    }

    /**
     * Invitaciones de colaboración (a cualquier actividad) recibidas por el
     * usuario autenticado. ?estado= filtra por pendiente/aceptado/rechazado.
     */
    public function misInvitaciones(Request $request)
    {
        $query = CaracterizacionColaborador::with([
            'actividad.secretaria', 'actividad.dependencia', 'actividad.creador', 'invitadoPor',
        ])->where('usuario_id', Auth::id());

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        return response()->json($query->latest('created_at')->get());
    }

    /**
     * El usuario invitado acepta o rechaza una invitación de colaboración.
     */
    public function responderInvitacion(Request $request, CaracterizacionColaborador $colaborador)
    {
        abort_unless(Auth::id() === $colaborador->usuario_id, 403, 'No autorizado para responder esta invitación.');

        // Sin este chequeo, un usuario podía revertir unilateralmente su propio
        // rechazo (o su aceptación) en cualquier momento posterior, recuperando
        // acceso sin que el creador volviera a invitarlo.
        if ($colaborador->estado !== CaracterizacionColaborador::ESTADO_PENDIENTE) {
            return response()->json([
                'message' => 'Esta invitación ya fue respondida. Si necesitas cambiar tu respuesta, pide al creador que te invite de nuevo.',
            ], 422);
        }

        $validated = $request->validate([
            'accion' => ['required', Rule::in([CaracterizacionColaborador::ESTADO_ACEPTADO, CaracterizacionColaborador::ESTADO_RECHAZADO])],
        ]);

        $colaborador->estado = $validated['accion'];
        $colaborador->responded_at = now();
        $colaborador->save();

        $actividadTema = $colaborador->actividad?->tema ?? CaracterizacionActividad::whereKey($colaborador->actividad_id)->value('tema');
        \App\Models\CaracterizacionNotificacion::enviar(
            (int) $colaborador->invitado_por, 'invitacion_respuesta',
            (Auth::user()->nombre_completo ?? 'La persona invitada').($validated['accion'] === CaracterizacionColaborador::ESTADO_ACEPTADO ? ' aceptó' : ' rechazó').' tu invitación',
            '«'.$actividadTema.'»', '/caracterizacion/actividades/'.$colaborador->actividad_id
        );

        return response()->json($colaborador);
    }

    /**
     * Actividades de otras personas en las que el usuario autenticado colabora
     * (invitación aceptada) — alimenta la sección "Actividades Compartidas Conmigo".
     * Mismo formato de respuesta que index().
     */
    public function misColaboraciones(Request $request)
    {
        $query = CaracterizacionActividad::with(['dependencia', 'secretaria', 'creador'])
            ->withCount('asistentes')
            ->whereHas('colaboradores', function ($q) {
                $q->where('usuario_id', Auth::id())->where('estado', CaracterizacionColaborador::ESTADO_ACEPTADO);
            })
            ->latest();

        return response()->json($query->get());
    }

    /**
     * Consolidado de TODOS los listados que el usuario puede ver (con los mismos filtros del
     * listado: secretaría, dependencia, municipio, fechas, estado...; sin filtros = todo) en el
     * MISMO formato oficial FO-PDD-19 que el Excel de una actividad: la plantilla con los
     * ciudadanos de cada actividad apilados uno debajo del otro (numeración continua, firma y
     * Parte 2 de su actividad en cada fila). Una segunda hoja índice lista las actividades.
     */
    public function consolidadoExcel(Request $request)
    {
        $query = CaracterizacionActividad::with([
            'dependencia', 'secretaria', 'creador', 'seguimiento',
            'asistentes.problematicas', 'asistentes.enfoques', 'asistentes.seguimientoPropio',
        ])->visiblePara(Auth::user())->orderBy('fecha')->orderBy('id');

        $this->aplicarFiltros($query, $request);
        $actividades = $query->get();

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(resource_path('templates/FO-PDD-19.xlsx'));
        $sheet = $spreadsheet->getSheetByName('FO-PDD-19');
        $this->agregarEncabezadoInstitucional($sheet, now()->format('d-m-Y'));

        // Encabezado genérico del consolidado (no es de una actividad en particular).
        $fechas = $actividades->pluck('fecha')->filter()->sort()->values();
        $rangoFechas = $fechas->isEmpty() ? '' : ($fechas->first()->format('d/m/Y') === $fechas->last()->format('d/m/Y')
            ? $fechas->first()->format('d/m/Y')
            : $fechas->first()->format('d/m/Y').' a '.$fechas->last()->format('d/m/Y'));
        $municipios = $actividades->pluck('municipio')->filter()->unique()->values();

        $sheet->setCellValue($this->c('C1'), $this->sinFormula('CONSOLIDADO DE LISTADOS DE CARACTERIZACIÓN — '.$this->describirAlcance($request).' — '.$actividades->count().' actividad(es)'));
        $sheet->setCellValue($this->c('C2'), $rangoFechas);
        $sheet->setCellValue($this->c('Q2'), '');
        $this->escribirBannerFila3($sheet, 'VARIOS', $municipios->count() === 1 ? (string) $municipios->first() : 'VARIOS', 'Casanare');
        $this->estilizarBanner($sheet);
        $this->ajustarEncabezados($sheet);

        $items = [];
        $indice = [];
        $n = 0;
        foreach ($actividades as $act) {
            $desde = $n + 1;
            foreach ($act->asistentes as $a) {
                $items[] = ['a' => $a, 'seg' => $a->seguimientoPropio ?? $act->seguimiento, 'n' => ++$n];
            }
            $indice[] = [
                $act->codigo, $act->tema, optional($act->fecha)->format('d/m/Y'), $act->municipio,
                $act->secretaria?->nombre, $act->dependencia?->nombre, $act->creador?->nombre_completo,
                $act->estado, $act->estado_aprobacion, $act->asistentes->count(),
                $act->asistentes->count() ? "{$desde} a {$n}" : '—', $act->seguimiento ? 'Sí' : 'No',
            ];
        }

        $archivosTemporales = [];
        // Con cientos de filas, incrustar una imagen de firma por fila vuelve el archivo enorme y tarda más de lo que
        // aguanta el servidor: sobre ese tope el consolidado sale sin imágenes (las firmas siguen en el Excel y el PDF de cada actividad).
        $incluirFirmas = count($items) <= self::MAX_FILAS_CON_FIRMA;
        $siguiente = $this->volcarFilasExcel($sheet, $items, 8 + self::FILAS_ENCABEZADO, $archivosTemporales, true, $incluirFirmas);
        $this->ajustarImpresion($sheet, $siguiente);

        // Hoja índice: qué actividad aporta cada tramo de filas del consolidado.
        $h2 = $spreadsheet->createSheet()->setTitle('Actividades incluidas');
        $h2->fromArray(['Código', 'Tema', 'Fecha', 'Municipio', 'Secretaría', 'Dependencia', 'Creador', 'Estado', 'Aprobación', 'Ciudadanos', 'N° en el consolidado', 'Parte 2 completa'], null, 'A1');
        $h2->getStyle('A1:L1')->getFont()->setBold(true);
        foreach ($indice as $i => $linea) {
            $h2->fromArray(array_map(fn ($v) => is_string($v) ? $this->sinFormula($v) : $v, $linea), null, 'A'.($i + 2));
        }
        foreach (range('A', 'L') as $col) {
            $h2->getColumnDimension($col)->setAutoSize(true);
        }
        if (!$incluirFirmas) {
            $h2->setCellValue('A'.(count($indice) + 3), 'Nota: este consolidado tiene más de '.self::MAX_FILAS_CON_FIRMA.' filas, por eso la columna FIRMA no lleva imágenes. Las firmas están en el Excel y el PDF de cada actividad.');
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        $tmp = tempnam(sys_get_temp_dir(), 'consol_');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);
        $contenido = file_get_contents($tmp);
        unlink($tmp);
        foreach ($archivosTemporales as $img) {
            @unlink($img);
        }

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="consolidado-caracterizacion-'.now()->format('Ymd-His').'.xlsx"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Excel en el formato oficial FO-PDD-19 (el mismo de cada actividad y del consolidado) con una fila por cada
     * participación recibida, venga de la actividad que venga. Lo usa la exportación de «Estadísticas por variables».
     * Cada participación ya debe traer cargadas problematicas, enfoques, seguimientoPropio y actividad.seguimiento.
     *
     * @param  \Illuminate\Support\Collection<int, CaracterizacionAsistente>  $asistentes
     */
    public function excelDeParticipaciones(\Illuminate\Support\Collection $asistentes, string $tema, string $notaConsulta = ''): string
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(resource_path('templates/FO-PDD-19.xlsx'));
        $sheet = $spreadsheet->getSheetByName('FO-PDD-19');
        $this->agregarEncabezadoInstitucional($sheet, now()->format('d-m-Y'));

        $fechas = $asistentes->map(fn ($a) => $a->actividad?->fecha)->filter()->sort()->values();
        $rango = $fechas->isEmpty() ? '' : ($fechas->first()->format('d/m/Y') === $fechas->last()->format('d/m/Y')
            ? $fechas->first()->format('d/m/Y') : $fechas->first()->format('d/m/Y').' a '.$fechas->last()->format('d/m/Y'));
        $municipios = $asistentes->map(fn ($a) => $a->actividad?->municipio)->filter()->unique()->values();

        $sheet->setCellValue($this->c('C1'), $this->sinFormula($tema));
        $sheet->setCellValue($this->c('C2'), $rango);
        $sheet->setCellValue($this->c('Q2'), '');
        $this->escribirBannerFila3($sheet, 'VARIOS', $municipios->count() === 1 ? (string) $municipios->first() : 'VARIOS', 'Casanare');
        $this->estilizarBanner($sheet);
        $this->ajustarEncabezados($sheet);

        $items = $asistentes->values()->map(fn ($a, $i) => [
            'a' => $a, 'seg' => $a->seguimientoPropio ?? $a->actividad?->seguimiento, 'n' => $i + 1,
        ])->all();
        $archivosTemporales = [];
        $incluirFirmas = count($items) <= self::MAX_FILAS_CON_FIRMA;
        $siguiente = $this->volcarFilasExcel($sheet, $items, 8 + self::FILAS_ENCABEZADO, $archivosTemporales, true, $incluirFirmas);
        $this->ajustarImpresion($sheet, $siguiente);

        // Hoja con la consulta que originó el listado
        $h2 = $spreadsheet->createSheet()->setTitle('Consulta');
        $h2->setCellValue('A1', 'Estadísticas por variables — consulta');
        $h2->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $h2->setCellValue('A3', $this->sinFormula($notaConsulta));
        $h2->setCellValue('A5', 'Cada fila es una participación (una reunión); una misma persona puede aparecer en varias reuniones, cada una con su propio bien o servicio.');
        if (! $incluirFirmas) {
            $h2->setCellValue('A6', 'Con más de '.self::MAX_FILAS_CON_FIRMA.' filas la columna FIRMA no lleva imágenes; las firmas están en el Excel y el PDF de cada actividad.');
        }
        $h2->getColumnDimension('A')->setWidth(120);
        $h2->getStyle('A3:A6')->getAlignment()->setWrapText(true);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        $tmp = tempnam(sys_get_temp_dir(), 'listado_');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);
        $contenido = file_get_contents($tmp);
        unlink($tmp);
        foreach ($archivosTemporales as $img) {
            @unlink($img);
        }

        return $contenido;
    }

    /**
     * Texto corto del alcance del consolidado según los filtros aplicados (para el encabezado).
     */
    private function describirAlcance(Request $request): string
    {
        $partes = [];
        if ($request->filled('secretaria_id')) {
            $partes[] = 'Secretaría: '.(\App\Models\CaracterizacionSecretaria::find($request->integer('secretaria_id'))?->nombre ?? '—');
        }
        if ($request->filled('dependencia_id')) {
            $ids = array_filter(explode(',', $request->string('dependencia_id')), fn ($id) => $id !== '');
            $partes[] = 'Dependencia(s): '.\App\Models\CaracterizacionDependencia::whereIn('id', $ids)->pluck('nombre')->implode(', ');
        }
        if ($request->filled('municipio')) {
            $partes[] = 'Municipio: '.$request->string('municipio');
        }
        if ($request->filled('fecha_desde') || $request->filled('fecha_hasta')) {
            $partes[] = 'Fechas: '.($request->input('fecha_desde') ?: '…').' a '.($request->input('fecha_hasta') ?: '…');
        }

        return $partes ? implode(' · ', $partes) : 'Todas las secretarías y dependencias';
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

        $actividad->load(['asistentes.problematicas', 'asistentes.enfoques', 'asistentes.seguimientoPropio', 'dependencia', 'secretaria', 'seguimiento']);

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

        $actividad->load(['asistentes.problematicas', 'asistentes.enfoques', 'dependencia', 'secretaria', 'seguimiento']);
        $seguimiento = $actividad->seguimiento;

        $templatePath = resource_path('templates/FO-PDD-19.xlsx');
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheetByName('FO-PDD-19');
        $this->agregarEncabezadoInstitucional($sheet, optional($actividad->fecha)->format('d-m-Y') ?? now()->format('d-m-Y'));

        // ── Encabezado (TEMA / FECHA / HORA / QUIEN DIRIGE / MUNICIPIO / DEPARTAMENTO / TIPO DE EVENTO) ──
        $sheet->setCellValue($this->c('C1'), $actividad->tema);
        $sheet->setCellValue($this->c('C2'), optional($actividad->fecha)->format('d/m/Y'));
        $sheet->setCellValue($this->c('Q2'), \Carbon\Carbon::parse($actividad->hora)->format('h:i A'));
        $this->escribirBannerFila3($sheet, (string) $actividad->quien_dirige_expone, (string) $actividad->municipio, (string) $actividad->departamento);
        $this->estilizarBanner($sheet);
        $this->ajustarEncabezados($sheet);

        $marcarTipoEvento = function (string $celda, string $valorEsperado) use ($sheet, $actividad) {
            $celda = $this->c($celda);
            $texto = (string) $sheet->getCell($celda)->getValue();
            if ($actividad->tipo_evento === $valorEsperado) {
                $sheet->setCellValue($celda, 'X  '.$texto);
            }
        };
        $marcarTipoEvento('AI2', 'asistencia_tecnica');
        $marcarTipoEvento('AI3', 'capacitacion');
        $lineasAI4 = explode("\n", (string) $sheet->getCell($this->c('AI4'))->getValue());
        foreach ($lineasAI4 as $i => $linea) {
            if ($actividad->tipo_evento === 'socializacion' && str_contains($linea, 'Socialización')) {
                $lineasAI4[$i] = 'X  '.$linea;
            }
            if ($actividad->tipo_evento === 'otro' && str_contains($linea, 'Otro')) {
                $lineasAI4[$i] = 'X  '.$linea.' '.$actividad->otro_evento_detalle;
            }
        }
        $sheet->setCellValue($this->c('AI4'), implode("\n", $lineasAI4));

        // ── Filas de datos (empiezan en la fila 8, igual que en el formato original) ──
        $items = $actividad->asistentes->values()
            ->map(fn ($a, $i) => ['a' => $a, 'seg' => $a->seguimientoPropio ?? $seguimiento, 'n' => $i + 1])->all();
        $archivosTemporales = [];
        $siguiente = $this->volcarFilasExcel($sheet, $items, 8 + self::FILAS_ENCABEZADO, $archivosTemporales);
        $this->ajustarImpresion($sheet, $siguiente);

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
     * Escribe filas de ciudadanos (Parte 1 + Parte 2 de su actividad + firma) sobre la hoja
     * "FO-PDD-19" de la plantilla oficial, empezando en $fila. Lo usan el Excel de una actividad
     * y el consolidado (que apila las filas de varias actividades en el mismo formato).
     *
     * @param  array<int, array{a: CaracterizacionAsistente, seg: ?CaracterizacionSeguimiento, n: int}>  $items
     * @return int  la siguiente fila libre
     */
    private function volcarFilasExcel($sheet, array $items, int $fila, array &$archivosTemporales, bool $copiarEstilo = false, bool $incluirFirmas = true): int
    {
        // AG (Canal de Comunicación) y AH (Idiomas) se dejan sin escribir: esos ítems
        // (17 y 18) se eliminaron del formulario, pero la plantilla original conserva
        // esas columnas físicas para no alterar su estructura.
        $colsParte1 = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AI'];
        $colsParte2 = ['AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA', 'BB', 'BC'];

        $primera = $fila;
        foreach ($items as ['a' => $a, 'seg' => $seguimiento, 'n' => $numero]) {
            if ($fila > $primera) {
                // La plantilla solo trae formateadas unas pocas filas: en el consolidado cada fila
                // nueva copia el estilo (bordes, alineación) y la altura de la primera fila de datos.
                                $sheet->getRowDimension($fila)->setRowHeight($sheet->getRowDimension($primera)->getRowHeight());
            }
            $problematicas = $a->problematicas->pluck('valor')->all();
            $enfoques = $a->enfoques->pluck('valor')->all();
            $marca = fn (bool $cond) => $cond ? 'X' : null;

            $valoresParte1 = [
                $numero,
                $this->sinFormula($a->item1_nombre),
                $a->item2_tipo_documento,
                $this->sinFormula($a->item3_numero_documento),
                $this->sinFormula($a->item4_cargo_barrio_vereda),
                $this->sinFormula($a->item5_municipio),
                $marca($a->item6_zona === 'urbana'),
                $marca($a->item6_zona === 'rural'),
                $this->sinFormula(trim($a->item7_ubicacion_tipo.' '.$a->item7_ubicacion_detalle)),
                $this->sinFormula($a->item8_contacto_valor),
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
                $a->sector_codigo,
                $marca($a->item13_clasificacion_organizacion === 'Con Ánimo de Lucro'),
                $marca($a->item13_clasificacion_organizacion === 'Sin Ánimo de Lucro'),
                $marca(in_array('Discapacidad', $enfoques)),
                $marca(in_array('Cabeza de Hogar', $enfoques)),
                $marca(in_array('Víctimas de Conflicto', $enfoques)),
                $marca(in_array('Habitante de la calle', $enfoques)),
                $a->item15_edad,
                $a->item16_tamano_grupo_familiar,
                null, // firma -> imagen embebida aparte
            ];
            foreach ($colsParte1 as $i => $col) {
                if ($valoresParte1[$i] !== null) {
                    $sheet->setCellValue("{$col}{$fila}", $valoresParte1[$i]);
                }
            }

            // Una firma ilegible (imagen dañada) no debe tumbar toda la exportación — sobre todo en el
            // consolidado, con cientos de filas: se omite esa imagen y se registra el aviso.
            try {
            $binario = $incluirFirmas ? $a->firmaBinaria() : null;
            if ($binario !== null) {
                {
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

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Excel: no se pudo incrustar la firma del asistente '.$a->id.': '.$e->getMessage());
            }

            // Parte 2 (ítems 20–32) ahora vive una sola vez por actividad (CaracterizacionSeguimiento),
            // así que el mismo valor se repite en cada fila de ciudadano — igual que el formato físico
            // repite los datos del proyecto/beneficio en cada fila. La única excepción es el grupo
            // etáreo (ítem 25): ese sigue siendo por persona, calculado de la edad propia de cada fila.
            $valoresParte2 = [
                // El código sale del municipio de cada ciudadano; si no es de Casanare, el que tenga el seguimiento.
                \App\Support\CodigoDane::deMunicipio($a->item5_municipio) ?? $seguimiento?->item20_codigo_dane,
                $seguimiento?->item21_categoria,
                $seguimiento?->item21_bien_servicio,
                $seguimiento?->item22_descripcion_beneficio,
                optional($seguimiento?->item23_fecha_beneficio)->format('d/m/Y'),
                $seguimiento?->item24_gestion_inversion,
                CaracterizacionAsistente::calcularGrupoEtareo($a->item15_edad),
                $seguimiento?->item26_sector,
                $seguimiento?->item26_programa,
                $seguimiento?->item26_meta_producto,
                $seguimiento?->item27_nombre_proyecto,
                $seguimiento?->item28_ods,
                $seguimiento?->item28_ddhh,
                $seguimiento?->item28_pilares_paz,
                $seguimiento?->item29_politica_publica,
                $seguimiento?->item30_politica_mipg,
                $seguimiento?->item31_total_beneficiarios,
                $seguimiento?->item32_acto_tipo,
                $seguimiento?->item32_numero,
                optional($seguimiento?->item32_fecha)->format('d/m/Y'),
            ];
            foreach ($colsParte2 as $i => $col) {
                if ($valoresParte2[$i] !== null) {
                    $sheet->setCellValue("{$col}{$fila}", is_string($valoresParte2[$i]) ? $this->sinFormula($valoresParte2[$i]) : $valoresParte2[$i]);
                }
            }

            $fila++;
        }

        if ($fila > $primera) {
            $this->estilizarFilas($sheet, $primera, $fila - 1);
        }

        return $fila;
    }

    /** Filas que se agregan arriba de la plantilla para el logo y el título institucionales. */
    private const FILAS_ENCABEZADO = 5;

    /** Ancho mínimo (en caracteres de Excel) de las columnas con marca «X». */
    private const ANCHO_MINIMO_MARCA = 3.6;

    /** Máximo de filas del consolidado que llevan imagen de firma. */
    private const MAX_FILAS_CON_FIRMA = 400;

    /** Desplaza una referencia ("C1", "A3:J3") por las filas del encabezado institucional agregado. */
    private function c(string $ref): string
    {
        return preg_replace_callback('/\d+/', fn ($m) => (string) ((int) $m[0] + self::FILAS_ENCABEZADO), $ref);
    }

    /**
     * La plantilla original muestra el logo y el título solo en el ENCABEZADO DE IMPRESIÓN (no se ven al abrir
     * el archivo). Se agregan 5 filas arriba con lo mismo que lleva el PDF: logo de la Gobernación a la
     * izquierda y, a la derecha, título, código del formato, fecha y versión. El encabezado de impresión se
     * quita para que no salga duplicado al imprimir.
     */
    private function agregarEncabezadoInstitucional($sheet, string $fecha): void
    {
        $sheet->insertNewRowBefore(1, self::FILAS_ENCABEZADO);
        for ($i = 1; $i <= self::FILAS_ENCABEZADO; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(19);
        }
        // Las columnas de la plantilla traen borde por defecto: el bloque del logo va sin bordes ni relleno, como en el PDF.
        $bloque = $sheet->getStyle('A1:AI'.self::FILAS_ENCABEZADO);
        $bloque->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE);
        $bloque->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);

        $logo = public_path('icons/gobernacion_nit.png');
        if (is_file($logo)) {
            try {
                $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $d->setName('Logo Gobernación de Casanare');
                $d->setPath($logo);
                $d->setHeight(118);
                $d->setCoordinates('A1');
                $d->setOffsetX(8);
                $d->setOffsetY(3);
                $d->setWorksheet($sheet);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Excel: no se pudo incrustar el logo: '.$e->getMessage());
            }
        }

        $derecha = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT;
        $lineas = [
            1 => ['CONTROL DE ASISTENCIA TECNICA Y CARACTERIZACION DE CIUDADANÍA', 12, true],
            2 => ['FO-PDD-19', 9, false],
            3 => [$fecha, 9, false],
            4 => ['V.08', 9, false],
        ];
        foreach ($lineas as $fila => [$texto, $tam, $negrita]) {
            $sheet->mergeCells("P{$fila}:AI{$fila}");
            $sheet->setCellValue("P{$fila}", $texto);
            $sheet->getStyle("P{$fila}")->getFont()->setName('Arial')->setSize($tam)->setBold($negrita);
            $sheet->getStyle("P{$fila}")->getAlignment()->setHorizontal($derecha)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }

        $sheet->getHeaderFooter()->setOddHeader('');
    }

    /** Área de impresión completa (la de la plantilla solo cubría 7 filas de datos). */
    private function ajustarImpresion($sheet, int $siguienteFila): void
    {
        $ultima = max($siguienteFila - 1, 14 + self::FILAS_ENCABEZADO);
        $sheet->getPageSetup()->setPrintArea('A1:AI'.$ultima);
    }

    /**
     * Fila 3 del encabezado: «QUIEN DIRIGE», FIRMA y MUNICIPIO van en una sola celda que se
     * combina de A a N y se reduce para caber; antes el texto largo se cortaba al llegar a
     * la celda «DEPARTAMENTO» y el municipio no se veía.
     */
    private function escribirBannerFila3($sheet, string $quienDirige, string $municipio, string $departamento): void
    {
        $sheet->mergeCells($this->c('A3:J3'));
        $sheet->setCellValue($this->c('A3'), $this->sinFormula('QUIEN DIRIGE Y/O EXPONE: '.$quienDirige.'     FIRMA: ____________     MUNICIPIO: '.$municipio));
        $sheet->setCellValue($this->c('K3'), '');
        $sheet->setCellValue($this->c('W3'), $this->sinFormula($departamento));
    }

    /**
     * Títulos de columnas de la plantilla que había que corregir: «Tipo Documento» se salía de su
     * celda y el enfoque de género debe nombrar también «(T) Transgénero» (la columna T).
     */
    private function ajustarEncabezados($sheet): void
    {
        // Los ítems 17 (Canal de Comunicación) y 18 (Idiomas, Lenguas o dialectos) se quitaron del formulario y del PDF;
        // la plantilla conserva sus columnas físicas (AG y AH), así que se ocultan para que el Excel quede igual que el PDF.
        foreach (['AG', 'AH'] as $columnaQuitada) {
            $sheet->getColumnDimension($columnaQuitada)->setVisible(false);
        }

        // Las columnas que llevan una «X» (zona, género, étnico, problemática, clasificación, enfoque) o el número
        // del sector son muy angostas en la plantilla (la de «Institucional» mide 1,45): la marca se veía cortada.
        // Se garantiza un ancho mínimo para que quepa completa.
        foreach (['G', 'H', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD'] as $columnaMarca) {
            $dimension = $sheet->getColumnDimension($columnaMarca);
            if ($dimension->getWidth() < self::ANCHO_MINIMO_MARCA) {
                $dimension->setWidth(self::ANCHO_MINIMO_MARCA);
            }
        }

        [$c5, $k6] = [$this->c('C5'), $this->c('K6')];
        $sheet->setCellValue($c5, "Tipo\nDocu-\nmento (2)");
        $sheet->getStyle($c5)->getAlignment()->setWrapText(true);
        $sheet->getStyle($c5)->getFont()->setSize(8);

        $sheet->setCellValue($k6, "Mujer Hombre\nNo Binario\n(T) Transgénero");
        $sheet->getStyle($k6)->getAlignment()->setWrapText(true);
        $sheet->getStyle($k6)->getFont()->setSize(7);
    }

    /** Valores del encabezado con la misma alineación y fuente (antes cada celda tenía la suya). */
    private function estilizarBanner($sheet): void
    {
        foreach (['C1', 'C2', 'Q2', 'W3'] as $celda) {
            $celda = $this->c($celda);
            $sheet->getStyle($celda)->getFont()->setName('Arial')->setSize(10)->setBold(false);
            $sheet->getStyle($celda)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                ->setShrinkToFit(true);
        }
        $sheet->getStyle($this->c('A3'))->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(false)->setShrinkToFit(true);
    }

    /**
     * Estilo uniforme del bloque de filas de datos: centrado, Arial 10 y ajuste de texto. Las filas que
     * trae la plantilla tienen alineaciones distintas entre sí (por eso «Jóvenes» salía a veces
     * a la izquierda y más grande). Las columnas de texto largo van a la izquierda.
     */
    private function estilizarFilas($sheet, int $desde, int $hasta): void
    {
        $rango = "A{$desde}:BC{$hasta}";
        $sheet->getStyle($rango)->getFont()->setName('Arial')->setSize(10)->setBold(false);
        $sheet->getStyle($rango)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        foreach (['B', 'E', 'I', 'AK', 'AL', 'AM', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY'] as $col) {
            $sheet->getStyle("{$col}{$desde}:{$col}{$hasta}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }
        $sheet->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    /**
     * Neutraliza inyección de fórmulas en Excel: los campos 1/3/4/5/7/8 los llena
     * un ciudadano anónimo sin autenticación (PublicController::registrarCiudadano),
     * y PhpSpreadsheet interpreta como fórmula cualquier valor que empiece con
     * =, +, -, @ (o tab/retorno de carro) — ej. un nombre "=HYPERLINK(...)" quedaría
     * como un enlace/fórmula viva al abrir el .xlsx. Se antepone una comilla simple,
     * la misma mitigación estándar (OWASP) para inyección de fórmulas en CSV/Excel.
     */
    private function sinFormula(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return $valor;
        }

        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'".$valor : $valor;
    }

    /**
     * Verifica que el usuario autenticado pueda ver/editar esta actividad según
     * su perfil de caracterización (mismo criterio que scopeVisiblePara), o porque
     * es un colaborador invitado por el creador y que ya aceptó la invitación.
     */
    private function autorizarAcceso(CaracterizacionActividad $actividad): void
    {
        $perfil = Auth::user()->caracterizacionPerfil;

        $esColaboradorAceptado = CaracterizacionColaborador::where('actividad_id', $actividad->id)
            ->where('usuario_id', Auth::id())
            ->where('estado', CaracterizacionColaborador::ESTADO_ACEPTADO)
            ->exists();

        $autorizado = $esColaboradorAceptado || ($perfil && $perfil->activo && (
            $perfil->rol === CaracterizacionPerfil::ROL_ADMINISTRADOR
            || ($perfil->rol === CaracterizacionPerfil::ROL_SECRETARIA && $perfil->secretaria_id === $actividad->secretaria_id)
            || ($perfil->rol === CaracterizacionPerfil::ROL_CONTRATISTA && Auth::id() === $actividad->creador_id)
        ));

        abort_unless($autorizado, 403, 'No autorizado para esta actividad.');
    }
}
