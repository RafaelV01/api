<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Lista de Asistencia</title>
    <style>
        @page { 
            margin: 20px 40px 50px 40px; 
            size: landscape;
        }
        body { font-family: 'Helvetica', sans-serif; font-size: 9px; }
        .main-table { width: 100%; border-collapse: collapse; }
        
        .main-table td, .main-table th { 
            border: 1px solid #000; 
            padding: 2px 4px;
            vertical-align: middle; 
        }
        .main-table th { text-align: center; font-weight: bold; }
        
        .firma-cell { 
            height: 24px;
            width: 150px; 
            text-align: center; 
        }
        .firma-img { 
            max-width: 148px;
            max-height: 23px;
            margin: 0 auto; 
            display: block; 
        }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .page-break { page-break-before: always; }

        .form-layout-table, .nested-table {
            width: 100%;
            border-collapse: collapse;
        }
        .form-layout-table td, .nested-table td {
            border: none;
            padding: 0px 2px;
            vertical-align: baseline; 
            height: 18px;
        }
        .form-label {
            font-weight: bold;
            padding-right: 5px;
            white-space: nowrap;
        }
        .form-data {
            width: 100%;
        }
        .line-wrapper {
            border-bottom: 0.5px solid #000;
            padding-top: 2px;
            min-height: 14px;
        }
        .event-box {
            border: 1px solid #000;
            width: 100%;
            border-collapse: collapse;
        }
        .event-box-title {
            font-weight: bold;
            text-align: center;
            padding: 2px;
            border-bottom: 1px solid #000;
        }
        .event-box td {
            padding: 1px 4px;
        }

        .footer {
            position: fixed; 
            bottom: -30px;
            left: 0px; 
            right: 0px;
            height: 40px; 
            font-size: 8px;
        }
        .page-number:after {
            content: counter(page);
        }
    </style>
</head>
<body>

@php
    $paginasDeAsistentes = $reunion->asistentes->count() > 0 ? $reunion->asistentes->chunk(15) : [collect()];
@endphp

<div class="footer">
    <table style="width: 100%;">
        <tr>
            <td style="text-align: center;">
                Carrera 20 No. 8-02, piso 5 CAD, Tel. 6336339, Ext. 1550-1551, Yopal, Casanare<br>
                <a href="http://www.casanare.gov.co" style="color: #0000EE; text-decoration: underline;">www.casanare.gov.co</a> – politicasectorial@casanare.gov.co
            </td>
            <td style="text-align: right; width: 15%;">
                <span class="page-number"></span> de {{ $paginasDeAsistentes->count() }}
            </td>
        </tr>
    </table>
</div>

<main>
@foreach ($paginasDeAsistentes as $pageIndex => $asistentesDePagina)

    @if ($pageIndex > 0)
        <div class="page-break"></div>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
        <tbody>
            <tr>
                <td style="width: 20%; vertical-align: top; border: none;">
                    <img src="{{ public_path('icons/logo.png') }}" alt="Logo Gobernación" style="width: 100px;">
                </td>
                <td style="width: 80%; text-align: right; vertical-align: top; font-family: 'Helvetica', sans-serif;">
                    <div style="font-size: 14px; font-weight: bold;">LISTA ASISTENCIA GENERAL</div>
                    <div style="font-size: 11px;">FO-GD-12</div>
                    <div style="font-size: 11px;">19-09-2024</div>
                    <div style="font-size: 11px;">V. 04</div>
                </td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
        <tbody>
            <tr>
                <td style="width: 77%; vertical-align: top; border: none; padding: 0;">
                    <table class="form-layout-table">
                        <tbody>
                            <tr>
                                <td colspan="2">
                                    <table class="nested-table">
                                        <tr>
                                            <td class="form-label">TEMA:</td>
                                            <td class="form-data"><div class="line-wrapper">{!! $reunion->tema ? e($reunion->tema) : '&nbsp;' !!}</div></td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <table class="nested-table">
                                        <tr>
                                            <td style="width: 65%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                    <tr>
                                                        <td class="form-label">FECHA:</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->fecha ? e(\Carbon\Carbon::parse($reunion->fecha)->locale('es')->format('d \d\e F \d\e Y')) : '&nbsp;' !!}</div></td>
                                                    </tr>
                                                </table>
                                            </td>
                                            <td style="width: 35%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                    <tr>
                                                        <td class="form-label">HORA:</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->hora_inicio ? e(\Carbon\Carbon::parse($reunion->hora_inicio)->format('h:i A')) : '&nbsp;' !!}</div></td>
                                                        <td style="padding: 0 4px; vertical-align: bottom;">a</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->hora_fin ? e(\Carbon\Carbon::parse($reunion->hora_fin)->format('h:i A')) : '&nbsp;' !!}</div></td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                     <table class="nested-table">
                                        <tr>
                                            <td style="width: 65%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                    <tr>
                                                        <td class="form-label">DEPENDENCIA/LUGAR:</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->dependencia_lugar ? e($reunion->dependencia_lugar) : '&nbsp;' !!}</div></td>
                                                    </tr>
                                                </table>
                                            </td>
                                            <td style="width: 35%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                    <tr>
                                                        <td class="form-label">CIUDAD/MPIO:</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->ciudad_municipio ? e($reunion->ciudad_municipio) : '&nbsp;' !!}</div></td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                     <table class="nested-table">
                                        <tr>
                                            <td style="width: 70%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                    <tr>
                                                        <td class="form-label">QUIEN DIRIGE y/o EXPONE:</td>
                                                        <td class="form-data"><div class="line-wrapper">{!! $reunion->expositor ? e($reunion->expositor) : '&nbsp;' !!}</div></td>
                                                    </tr>
                                                </table>
                                            </td>
                                            <td style="width: 30%; vertical-align: bottom;">
                                                <table style="width: 100%;">
                                                <tr>
    <td class="form-label">FIRMA:</td>
    <td class="form-data">
        <div class="line-wrapper" style="min-height:20px;">
            @if(!empty($reunion->firma_creador))
                <img 
                    src="{{ $reunion->firma_creador }}" 
                    alt="Firma Creador" 
                    style="max-width:100px;max-height:18px;display:block;margin:0 auto;"
                />
            @else
                &nbsp;
            @endif
        </div>
    </td>
</tr>

                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
                <td style="width: 23%; vertical-align: top; border: none; padding-left: 10px;">
                    <table class="event-box">
                        <thead>
                            <tr>
                                <th colspan="2" class="event-box-title">TIPO DE EVENTO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="form-label">CAPACITACIÓN:</td>
                                <td class="form-data" style="width: 60%;">
                                    <div class="line-wrapper" style="text-align: center;">
                                        {!! $reunion->tipo_evento === 'capacitacion' ? 'X' : '&nbsp;' !!}
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="form-label">DIVULGACIÓN:</td>
                                <td class="form-data">
                                    <div class="line-wrapper" style="text-align: center;">
                                        {!! $reunion->tipo_evento === 'divulgacion' ? 'X' : '&nbsp;' !!}
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="form-label">OTRO:</td>
                                <td class="form-data">
                                    <div class="line-wrapper">
                                        {!! $reunion->tipo_evento === 'otro' ? ($reunion->otro_evento ?? 'X') : '&nbsp;' !!}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th>NOMBRE COMPLETO</th>
                <th>CARGO</th>
                <th>DEPENDENCIA</th>
                <th>CORREO ELECTRONICO</th>
                <th width="10%">TELEFONO (CEL)</th>
                <th width="10%">FIRMA</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($asistentesDePagina as $asistencia)
                <tr>
                    <td class="text-center">{{ ($pageIndex * 15) + $loop->iteration }}</td>
                    <td>{{ e($asistencia->nombre_completo) }}</td>
                    <td>{{ e($asistencia->cargo) }}</td>
                    <td>{{ e($asistencia->dependencia) }}</td>
                    <td>{{ e($asistencia->email) }}</td>
                    <td>{{ e($asistencia->telefono) }}</td>
                    <td class="firma-cell">
                        @if($asistencia->firma)
                            <img src="{{ $asistencia->firma->data_base64 }}" alt="Firma" class="firma-img">
                        @endif
                    </td>
                </tr>
            @endforeach

            @php
                $filasOcupadas = count($asistentesDePagina);
            @endphp
            
            @for ($i = $filasOcupadas; $i < 15; $i++)
                <tr>
                    <td class="text-center">{{ ($pageIndex * 15) + $i + 1 }}</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td class="firma-cell">&nbsp;</td>
                </tr>
            @endfor
        </tbody>
    </table>

</main>
@endforeach
</body>
</html>