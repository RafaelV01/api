<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caracterización de Ciudadanía - FO-PDD-19</title>
    <style>
        @page { margin: 0; size: 792pt 612pt; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'Arial', 'Helvetica', sans-serif; font-size: 5pt; color: #000; }

        .sheet { width: 768pt; margin: 2pt 12pt 8pt 12pt; }
        .page-break { page-break-before: always; }

        /* ---- Encabezado: logo + título ---- */
        .top-row { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .top-row td { border: none; vertical-align: top; padding: 0; }
        .logo-cell { width: 84pt; }
        .logo-cell img { width: 80pt; height: 80pt; }
        .title-cell { text-align: right; font-family: Arial, sans-serif; padding-top: 2pt; padding-right: 2pt; }
        .title-cell .titulo { font-size: 7.6pt; font-weight: bold; }
        .title-cell .sub { font-size: 5.6pt; line-height: 1.3; }

        /* ---- Bloque TEMA / FECHA / QUIEN DIRIGE ---- */
        .info-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 2pt; }
        .info-table td { border: 1pt solid #000; padding: 2pt 4pt; vertical-align: top; font-size: 7pt; }
        .info-table .lbl { font-weight: bold; white-space: nowrap; }
        .info-table .legal { font-size: 5.6pt; line-height: 1.35; font-weight: normal; }
        .info-table .tipo-evento-item { font-size: 7pt; padding-top: 4pt; }
        .info-table .tipo-evento-item .marca { font-weight: bold; }

        /* ---- Grilla principal (posicionamiento absoluto — dompdf no respeta bien */
        /* colgroup/width en tablas con colspan/rowspan anidados, así que las celdas */
        /* de la grilla se dibujan a mano con coordenadas exactas del PDF de referencia). */
        .grid { position: relative; margin-top: 0; border: 1pt solid #000; }
        .cell { position: absolute; overflow: hidden; text-align: center; }
        .cell.left { text-align: left; }
        .cell.no-clip { overflow: visible; }
        /* Centrado vertical fiable en dompdf: display:table/table-cell y flex demostraron ser
           inconsistentes entre versiones de dompdf (funcionan en una prueba aislada y fallan
           en la plantilla real, o viceversa). La única técnica 100% determinista es calcular en
           PHP el padding-top exacto a partir del número de líneas conocido (por los <br> del
           label) y la altura real de la celda — sin depender de ningún truco de layout de dompdf. */
        .cell-pad { padding: 0 1.5pt; }
        .vline { position: absolute; width: 1pt; background: #000; }
        .hline { position: absolute; height: 1pt; background: #000; }
        .head-bg { position: absolute; background: #f2f2f2; }
        .head-label { font-size: 5pt; line-height: 5.6pt; }
        .head-group { font-size: 3.8pt; line-height: 4.3pt; }
        .head-sub { font-size: 4.4pt; line-height: 5pt; }
        .head-letter { font-size: 5pt; line-height: 5.6pt; }
        .rot-wrap { position: relative; width: 100%; height: 100%; }
        .rot-text {
            position: absolute;
            top: 50%; left: 50%;
            width: 66pt;
            margin-left: -33pt;
            margin-top: -3pt;
            transform: rotate(-90deg);
            white-space: nowrap;
            text-align: center;
            overflow: visible;
            font-size: 3.6pt;
        }

        .data-cell { font-size: 5pt; line-height: 5.6pt; }
        .x-mark { font-weight: bold; font-size: 5.5pt; }
        .firma-img { display: block; margin: 0 auto; }

        .footer-block { text-align: center; font-size: 6pt; margin-top: 3pt; }
    </style>
</head>
<body>

@php
    \Carbon\Carbon::setLocale('es');
    $porPagina = 10;
    $totalAsistentes = $actividad->asistentes->count();
    $totalPaginas = max(1, (int) ceil($totalAsistentes / $porPagina));
    $paginas = collect(range(0, $totalPaginas - 1))->map(
        fn ($i) => $actividad->asistentes->slice($i * $porPagina, $porPagina)->values()
    );

    $tipoEventoMarca = fn (string $valor) => $actividad->tipo_evento === $valor ? 'X' : '';

    // ── Geometría exacta de la grilla, tomada del PDF institucional FO-PDD-19 ──
    $colW = [14.6, 119.3, 22.0, 48.1, 53.3, 60.0, 8.7, 11.9, 29.1, 55.4, 8.6, 8.6, 8.6, 8.7, 10.7, 11.3, 11.3, 11.3, 5.2, 9.5, 6.7, 6.7, 10.7, 10.7, 13.4, 13.5, 10.7, 10.7, 10.6, 10.7, 13.8, 7.1, 11.0, 18.4, 91.2];
    $colX = [];
    $acc = 0.0;
    foreach ($colW as $w) { $colX[] = $acc; $acc += $w; }
    $gridW = $acc; // 762.1pt

    $H1 = 24.1; $H2 = 24.8; $H3 = 23.4;
    $headerH = $H1 + $H2 + $H3; // 72.3pt
    $rowH = 24.7;
    $gridH = $headerH + $porPagina * $rowH;

    // Centrado vertical: padding-top calculado a partir del número de líneas (contando <br>)
    // y la altura real de línea usada por cada clase de texto — ver nota en el <style>.
    $padTop = function (string $label, float $cellHeight, float $lineHeight): float {
        $numLines = substr_count($label, '<br>') + 1;
        return max(0, round(($cellHeight - $numLines * $lineHeight) / 2, 1));
    };

    // Ítems de encabezado que ocupan todo el alto (rowspan=3 equivalente)
    $fullHeight = [
        [0, 'N°', false, false],
        [1, "Nombres y Apellidos y/o Nombre de la Entidad u Organización Social (1)", false, true],
        [2, 'Tipo Documento(2)', false, false],
        [3, 'Número Documento de Identidad y/o NIT (3)', false, false],
        [4, "Cargo (4)<br>Barrio /Vereda", false, false],
        [5, 'Municipio (5)', false, true],
        [8, "Ubicación (7)<br>Dirección/<br>nombre del<br>predio", false, true],
        [9, 'Número de Contacto (8)', false, true],
        [23, 'SECTOR De La ORGANIZACIÓN (12)', true, false],
        [30, 'EDAD - grupo etário (15)', true, false],
        [31, 'Tamaño Grupo Familiar (16)', true, false],
        [32, 'Canal de Comunicación (17)', true, false],
        [33, 'Idiomas, Lenguas o dialectos (18)', true, false],
        [34, 'FIRMA (19)', false, true],
    ];

    // ZONA: ocupa fila1+fila2 combinadas en una sola celda (sin divisor interno), igual que el PDF fuente.
    $row12Groups = [
        [6, 2, "ZONA<br>Urbana / Rural (6)"],
    ];

    // Etiquetas de grupo, banda superior únicamente (fila 1)
    $row1Groups = [
        [10, 5, 'ENFOQUE DE GÉNERO (9)'],
        [15, 3, 'ENFOQUE ÉTNICO (10)'],
        [18, 5, "PROBLEMÁTICA<br>A ATENDER (11)"],
        [24, 2, "CLASIFICACIÓN<br>DE LA<br>ORGANIZACIÓN<br>N.(13)"],
        [26, 4, "ENFOQUE<br>DIFERENCIAL/POB<br>LACIONAL (14)"],
    ];

    // Fila 2: [colIdxInicio, colSpan, etiqueta, ocupaTambienFila3 (=> rotado, igual que el PDF fuente)]
    $row2Items = [
        [10, 4, "Mujer Hombre<br>No Binario", false],
        [14, 1, 'LGBTIQ+ OSIGD', true],
        [15, 1, 'Indígenas', true],
        [16, 1, "Comun. Negras - Afro-<br>Raizales - Palenqueras", true],
        [17, 1, 'Pueblo Rom o Gitano', true],
        [18, 1, 'Institucional', true],
        [19, 1, 'Ambiental', true],
        [20, 1, 'Salud', true],
        [21, 1, 'Cultural', true],
        [22, 1, 'Educativa', true],
        [24, 1, 'Con Animo de Lucro', true],
        [25, 1, 'Sin Animo de Lucro', true],
        [26, 1, 'Discapacidad', true],
        [27, 1, 'Cabeza de Hogar', true],
        [28, 1, 'Víctimas de Conflicto', true],
        [29, 1, 'Habitante de la calle', true],
    ];

    // Fila 3: [colIdx, etiqueta]
    $row3Items = [[6, 'U'], [7, 'R'], [10, 'F'], [11, 'M'], [12, 'NB'], [13, 'T']];

    // Segmentos de línea horizontal divisoria fila1/fila2 (ZONA queda fuera: es una sola celda) y fila2/fila3
    $hDividerRow12 = [[10, 15], [15, 18], [18, 23], [24, 26], [26, 30]];
    $hDividerRow23 = [[6, 8], [10, 14]];

    $x = fn ($i) => $colX[$i] ?? $gridW;
    $w = fn ($i, $span = 1) => array_sum(array_slice($colW, $i, $span));
@endphp

<div class="sheet">
@foreach ($paginas as $pageIndex => $asistentesDePagina)
    @if ($pageIndex > 0) <div class="page-break"></div> @endif

    <table class="top-row">
        <tr>
            <td class="logo-cell"><img src="{{ public_path('icons/gobernacion_nit.png') }}" alt="Gobernación de Casanare"></td>
            <td class="title-cell">
                <div class="titulo">CONTROL DE ASISTENCIA TECNICA Y CARACTERIZACION DE CIUDADANÍA</div>
                <div class="sub">
                    FO-PDD-19<br>
                    {{ \Carbon\Carbon::parse($actividad->fecha)->format('d-m-Y') }}<br>
                    V.08
                </div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <colgroup>
            <col width="145.6pt">
            <col width="530.8pt">
            <col width="91.6pt">
        </colgroup>
        <tr>
            <td class="lbl">TEMA:</td>
            <td colspan="1">{{ $actividad->tema }}</td>
            <td class="tipo-evento-item" rowspan="1"><span style="font-weight:bold;">TIPO DE EVENTO</span></td>
        </tr>
        <tr>
            <td class="lbl">FECHA: <span style="font-weight:normal;">{{ \Carbon\Carbon::parse($actividad->fecha)->locale('es')->isoFormat('DD [de] MMMM [de] YYYY') }}</span></td>
            <td class="lbl">HORA: <span style="font-weight:normal;">{{ \Carbon\Carbon::parse($actividad->hora)->format('h:i A') }}</span></td>
            <td class="tipo-evento-item"><span class="marca">{{ $tipoEventoMarca('asistencia_tecnica') }}</span> Asistencia Técnica:</td>
        </tr>
        <tr>
            <td colspan="2" class="lbl">
                QUIEN DIRIGE Y/O EXPONE: <span style="font-weight:normal;">{{ $actividad->quien_dirige_expone }}</span>
                &nbsp;&nbsp;&nbsp; FIRMA: ______________
                &nbsp;&nbsp;&nbsp; MUNICIPIO: <span style="font-weight:normal;">{{ $actividad->municipio }}</span>
                &nbsp;&nbsp;&nbsp; DEPARTAMENTO: <span style="font-weight:normal;">{{ $actividad->departamento }}</span>
            </td>
            <td class="tipo-evento-item"><span class="marca">{{ $tipoEventoMarca('capacitacion') }}</span> Capacitación:</td>
        </tr>
        <tr>
            <td colspan="2" class="legal">
                La Gobernación de Casanare en cumplimiento de la Ley 1581 de 2012, el Decreto reglamentario 1377 de 2013 referente a Protección de Datos Personales, los datos aquí consignados serán tratados mediante el uso y mantenimiento de seguridad técnicas, físicas y administrativas a fin de impedir que terceros no autorizados accedan a los mismos.<br>
                L: Lesbianas, G: Gays,B: Bisexuales,T: Transexuales,I: Intersexuales, Q: Queer-+: Colectivos no representados en las siglas, PPL: Personas Privadas de la Libertad, -TD: Número de identificación Personas Privadas de la Libertad PPR: Personas en Proceso de Reintegración Y LGBTIQ+ OSIGD
            </td>
            <td class="tipo-evento-item">
                <span class="marca">{{ $tipoEventoMarca('socializacion') }}</span> Socialización:<br><br>
                <span class="marca">{{ $tipoEventoMarca('otro') }}</span> Otro: {{ $actividad->otro_evento_detalle }}
            </td>
        </tr>
    </table>

    <div class="grid" style="width: {{ $gridW }}pt; height: {{ $gridH }}pt;">
        {{-- Fondos grises del encabezado --}}
        <div class="head-bg" style="left:0pt; top:0pt; width:{{ $gridW }}pt; height:{{ $headerH }}pt;"></div>

        {{-- Ítems de altura completa --}}
        @foreach ($fullHeight as [$ci, $label, $rot, $left])
            <div class="cell cell-pad {{ $left ? 'left' : '' }} {{ $rot ? 'no-clip' : '' }}" style="left:{{ $x($ci) }}pt; top:0pt; width:{{ $w($ci) }}pt; height:{{ $headerH }}pt;">
                @if ($rot)
                    <div class="rot-wrap"><span class="rot-text">{!! $label !!}</span></div>
                @else
                    <div class="head-label" style="padding-top:{{ $padTop($label, $headerH, 5.6) }}pt;">{!! $label !!}</div>
                @endif
            </div>
        @endforeach

        {{-- ZONA: fila1+fila2 combinadas en una sola celda (sin divisor interno) --}}
        @foreach ($row12Groups as [$ci, $span, $label])
            <div class="cell cell-pad" style="left:{{ $x($ci) }}pt; top:0pt; width:{{ $w($ci, $span) }}pt; height:{{ $H1 + $H2 }}pt;">
                <div class="head-label" style="padding-top:{{ $padTop($label, $H1 + $H2, 5.6) }}pt;">{!! $label !!}</div>
            </div>
        @endforeach

        {{-- Etiquetas de grupo (fila 1) --}}
        @foreach ($row1Groups as [$ci, $span, $label])
            <div class="cell cell-pad" style="left:{{ $x($ci) }}pt; top:0pt; width:{{ $w($ci, $span) }}pt; height:{{ $H1 }}pt;">
                <div class="head-label head-group" style="padding-top:{{ $padTop($label, $H1, 4.3) }}pt;">{!! $label !!}</div>
            </div>
        @endforeach

        {{-- Fila 2 (los que ocupan también fila 3 van rotados, igual que el PDF fuente) --}}
        @foreach ($row2Items as [$ci, $span, $label, $alsoRow3])
            @php
                $item2H = $alsoRow3 ? $H2 + $H3 : $H2;
                $rotLines = $alsoRow3 && str_contains($label, '<br>') ? 2 : 1;
                $rotW = round($item2H * 0.92, 1);
                $rotMarginTop = $rotLines === 2 ? -4.6 : -3;
            @endphp
            <div class="cell cell-pad {{ $alsoRow3 ? 'no-clip' : '' }}" style="left:{{ $x($ci) }}pt; top:{{ $H1 }}pt; width:{{ $w($ci, $span) }}pt; height:{{ $item2H }}pt;">
                @if ($alsoRow3)
                    <div class="rot-wrap"><span class="rot-text" style="width:{{ $rotW }}pt; margin-left:-{{ $rotW / 2 }}pt; margin-top:{{ $rotMarginTop }}pt; {{ $rotLines === 2 ? 'white-space:normal; line-height:4.2pt;' : '' }}">{!! $label !!}</span></div>
                @else
                    <div class="head-label head-sub" style="padding-top:{{ $padTop($label, $item2H, 5) }}pt;">{!! $label !!}</div>
                @endif
            </div>
        @endforeach

        {{-- Fila 3 --}}
        @foreach ($row3Items as [$ci, $label])
            <div class="cell cell-pad" style="left:{{ $x($ci) }}pt; top:{{ $H1 + $H2 }}pt; width:{{ $w($ci) }}pt; height:{{ $H3 }}pt;">
                <div class="head-label head-letter" style="padding-top:{{ $padTop($label, $H3, 5.6) }}pt;">{{ $label }}</div>
            </div>
        @endforeach

        {{-- Líneas verticales: columnas de altura completa (0 a gridH) --}}
        @foreach ($fullHeight as [$ci, $label, $rot, $left])
            <div class="vline" style="left:{{ $x($ci) }}pt; top:0pt; height:{{ $gridH }}pt;"></div>
        @endforeach
        {{-- Líneas verticales: ZONA, bloque combinado fila1+fila2 (0 a H1+H2), sin divisor interno --}}
        @foreach ($row12Groups as [$ci, $span, $label])
            <div class="vline" style="left:{{ $x($ci) }}pt; top:0pt; height:{{ $H1 + $H2 }}pt;"></div>
        @endforeach
        {{-- Líneas verticales: bordes de grupo en banda fila 1 (0 a H1) --}}
        @foreach ($row1Groups as [$ci, $span, $label])
            <div class="vline" style="left:{{ $x($ci) }}pt; top:0pt; height:{{ $H1 }}pt;"></div>
        @endforeach
        {{-- Líneas verticales: banda fila 2, respetando el span real de cada celda (no corta las celdas combinadas) --}}
        @foreach ($row2Items as [$ci, $span, $label, $alsoRow3])
            <div class="vline" style="left:{{ $x($ci) }}pt; top:{{ $H1 }}pt; height:{{ $alsoRow3 ? $H2 + $H3 : $H2 }}pt;"></div>
        @endforeach
        {{-- Líneas verticales: banda fila3 + filas de datos, en cada columna hoja --}}
        @for ($ci = 0; $ci <= 35; $ci++)
            <div class="vline" style="left:{{ $x($ci) }}pt; top:{{ $H1 + $H2 }}pt; height:{{ $gridH - $H1 - $H2 }}pt;"></div>
        @endfor

        {{-- Líneas horizontales fila1/fila2 y fila2/fila3 --}}
        @foreach ($hDividerRow12 as [$from, $to])
            <div class="hline" style="left:{{ $x($from) }}pt; top:{{ $H1 }}pt; width:{{ $x($to) - $x($from) }}pt;"></div>
        @endforeach
        @foreach ($hDividerRow23 as [$from, $to])
            <div class="hline" style="left:{{ $x($from) }}pt; top:{{ $H1 + $H2 }}pt; width:{{ $x($to) - $x($from) }}pt;"></div>
        @endforeach
        {{-- Línea horizontal fin de encabezado --}}
        <div class="hline" style="left:0pt; top:{{ $headerH }}pt; width:{{ $gridW }}pt;"></div>

        {{-- Filas de datos (siempre {{ $porPagina }}, rellenas en blanco si faltan) --}}
        @for ($index = 0; $index < $porPagina; $index++)
            @php
                $a = $asistentesDePagina->get($index);
                $problematicas = $a ? $a->problematicas->pluck('valor')->all() : [];
                $enfoques = $a ? $a->enfoques->pluck('valor')->all() : [];
                $marca = fn (bool $cond) => $cond ? 'X' : '';
                $rowTop = $headerH + $index * $rowH;
                $numero = ($pageIndex * $porPagina) + $index + 1;

                $valores = $a ? [
                    $numero,
                    $a->item1_nombre,
                    $a->item2_tipo_documento,
                    $a->item3_numero_documento,
                    $a->item4_cargo_barrio_vereda,
                    $a->item5_municipio,
                    $marca($a->item6_zona === 'urbana'),
                    $marca($a->item6_zona === 'rural'),
                    trim($a->item7_ubicacion_tipo.' '.$a->item7_ubicacion_detalle),
                    $a->item8_contacto_tipo.': '.$a->item8_contacto_valor,
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
                    null, // firma va aparte (imagen)
                ] : array_merge([$numero], array_fill(0, 33, ''), [null]);

                $leftAligned = [1, 4, 5, 8, 9, 23, 32, 33];
                $centered = [0, 2, 6, 7, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 25, 26, 27, 28, 29, 30, 31];
            @endphp

            {{-- línea horizontal inferior de la fila --}}
            <div class="hline" style="left:0pt; top:{{ $rowTop + $rowH }}pt; width:{{ $gridW }}pt;"></div>

            @for ($ci = 0; $ci < 35; $ci++)
                @if ($ci === 34)
                    <div class="cell firma-cell" style="left:{{ $x($ci) }}pt; top:{{ $rowTop }}pt; width:{{ $w($ci) }}pt; height:{{ $rowH }}pt;">
                        @if ($a && $a->firma_ciudadano_base64)
                            @php
                                // max-width/max-height de CSS no es fiable en dompdf para <img>: se calcula
                                // el tamaño exacto en pt a partir de las dimensiones reales de la firma,
                                // igual que "object-fit: contain", para que nunca se salga de la celda,
                                // y se centra verticalmente con el mismo padding-top calculado a mano
                                // (ver nota junto a $padTop) que el resto de celdas de la grilla.
                                $firmaMaxW = 86; $firmaMaxH = 22;
                                $firmaDispW = $firmaMaxW; $firmaDispH = $firmaMaxH;
                                if (str_contains($a->firma_ciudadano_base64, ',')) {
                                    [, $firmaB64] = explode(',', $a->firma_ciudadano_base64, 2);
                                    $firmaBin = base64_decode($firmaB64, true);
                                    $firmaInfo = $firmaBin !== false ? @getimagesizefromstring($firmaBin) : false;
                                    if ($firmaInfo) {
                                        $firmaScale = min($firmaMaxW / $firmaInfo[0], $firmaMaxH / $firmaInfo[1], 1);
                                        $firmaDispW = round($firmaInfo[0] * $firmaScale, 1);
                                        $firmaDispH = round($firmaInfo[1] * $firmaScale, 1);
                                    }
                                }
                                $firmaPadTop = max(0, round(($rowH - $firmaDispH) / 2, 1));
                            @endphp
                            <div style="padding-top:{{ $firmaPadTop }}pt;">
                                <img class="firma-img" style="width:{{ $firmaDispW }}pt; height:{{ $firmaDispH }}pt;" src="{{ $a->firma_ciudadano_base64 }}">
                            </div>
                        @endif
                    </div>
                @else
                    <div class="cell cell-pad data-cell {{ in_array($ci, $leftAligned) ? 'left' : '' }} {{ in_array($valores[$ci], ['X'], true) ? 'x-mark' : '' }}"
                         style="left:{{ $x($ci) }}pt; top:{{ $rowTop }}pt; width:{{ $w($ci) }}pt; height:{{ $rowH }}pt; padding-top:{{ $padTop((string) $valores[$ci], $rowH, 5.6) }}pt;">
                        {{ $valores[$ci] }}
                    </div>
                @endif
            @endfor
        @endfor
    </div>

    <div class="footer-block">
        Carrera 20 N°8-02, Cód. Postal 850001, Tel. 6336339, Ext.__________, Yopal, Casanare<br>
        www.casanare.gov.co - ________@casanare.gov.co &nbsp;·&nbsp; Actividad {{ $actividad->codigo }}
    </div>
@endforeach
</div>

</body>
</html>
