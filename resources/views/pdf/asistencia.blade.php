<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>FO-GD-12 - Lista de Asistencia</title>
  <style>
  @page { size: A4 landscape; margin: 0; }
  html, body { margin:0; padding:0; }
  .sheet {
    position: relative;
    width: 297mm; height: 210mm;           /* landscape */
    background: url('file://{{ public_path('plantillas/FO-GD-12.png') }}') no-repeat center top;
    background-size: 297mm 210mm;           /* landscape */
    font-family: Helvetica, Arial, sans-serif;
    font-size: 10px; color:#000;
  }
  .field { position: absolute; white-space: nowrap; }
  .center { text-align:center; }
</style>

</head>
<body>
  <div class="sheet">
    <!-- Encabezado -->
    <!-- TEMA -->
<!-- TEMA -->
<div class="field" style="left:28mm; top:42mm; width:135mm;">
  {{ $reunion->tema }}
</div>

<!-- FECHA -->
<div class="field" style="left:28mm; top:48mm; width:65mm;">
  {{ isset($reunion->fecha) ? \Carbon\Carbon::parse($reunion->fecha)->translatedFormat('d \d\e F \d\e Y') : '' }}
</div>

<!-- HORA INICIO -->
<div class="field center" style="left:185mm; top:48mm; width:24mm;">
  {{ isset($reunion->hora_inicio) ? \Carbon\Carbon::parse($reunion->hora_inicio)->format('h:i A') : '' }}
</div>

<!-- HORA FIN -->
<div class="field center" style="left:200mm; top:48mm; width:24mm;">
  {{ isset($reunion->hora_fin) ? \Carbon\Carbon::parse($reunion->hora_fin)->format('h:i A') : '' }}
</div>

<!-- DEPENDENCIA/LUGAR -->
<div class="field" style="left:52mm; top:54mm; width:60mm;">
  {{ $reunion->dependencia_lugar ?? '' }}
</div>

<!-- CIUDAD/MUNICIPIO -->
<div class="field" style="left:185mm; top:54mm; width:50mm;">
  {{ $reunion->ciudad_municipio ?? '' }}
</div>

<!-- FIRMA (LUGAR) -->
<div class="field" style="left:45mm; top:61mm; width:50mm;">
  {{ $reunion->firma_lugar ?? '' }}
</div>

<!-- QUIEN DIRIGE Y/O EXPONE -->
<div class="field" style="left:43mm; top:61mm; width:90mm;">
  {{ $reunion->expositor ?? '' }}
</div>

<!-- FIRMA EXPOSITOR -->
<div class="field" style="left:45mm; top:61mm; width:50mm;">
  {{ $reunion->firma_expositor ?? '' }}
</div>

@php
  $y0 = 77;   // posición inicial fila 1 (ajustada un poco más abajo)
  $dy = 7.4;  // separación entre filas (ligeramente menor para cuadrar al final)
  $as = $reunion->asistentes ?? [];
  $max = 15;
@endphp

@for($i=0; $i<$max; $i++)
  @php $top = $y0 + $i*$dy; $a = $as[$i] ?? null; @endphp

  <!-- NOMBRE COMPLETO -->
  <div class="field" style="left:22mm; top:{{ $top }}mm; width:56mm;">{{ $a->nombre_completo ?? '' }}</div>
  
  <!-- CARGO -->
  <div class="field" style="left:99mm; top:{{ $top }}mm; width:28mm;">{{ $a->cargo ?? '' }}</div>
  
  <!-- DEPENDENCIA -->
  <div class="field" style="left:131mm; top:{{ $top }}mm; width:34mm;">{{ $a->dependencia ?? '' }}</div>
  
  <!-- CORREO ELECTRONICO -->
  <div class="field" style="left:161mm; top:{{ $top }}mm; width:38mm;">{{ $a->email ?? '' }}</div>
  
  <!-- TELEFONO (CEL) -->
  <div class="field center" style="left:214mm; top:{{ $top }}mm; width:28mm;">{{ $a->telefono ?? '' }}</div>
  
  <!-- FIRMA -->
  @if(!empty($a->firma?->data_base64))
    <img class="field" style="left:255mm; top:{{ $top-2 }}mm; width:28mm; height:7mm;" src="{{ $a->firma->data_base64 }}" alt="Firma">
  @endif

@endfor


  </div>
</body>
</html>
