<?php

namespace App\Services;

use App\Models\CaracterizacionOpcion;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Lee un Excel en el formato oficial FO-PDD-19 (el mismo que exporta el sistema o que diligencia la Gobernación) y devuelve una fila
 * normalizada por persona. Las columnas se identifican por su posición en el formato; el comienzo de los datos se detecta buscando el
 * encabezado «Nombres y Apellidos», así que no importa cuántas filas de título tenga el archivo.
 */
class ImportadorExcelCaracterizacion
{
    public const MAX_FILAS = 20000;

    private const ENCABEZADO = '/nombres?\s*y\s*apellidos/iu';

    /** Opción marcada con «X» en un bloque de columnas, en el orden del formato. */
    private const GENERO = ['K' => 'Mujer', 'L' => 'Hombre', 'M' => 'No Binario', 'N' => 'Transgénero', 'O' => 'LGBTIQ+ OSIGD'];
    private const ETNICO = ['P' => 'Indígenas', 'Q' => 'Comun. Negras - Afro- Raizales - Palenqueras', 'R' => 'Pueblo Rom o Gitano'];
    private const PROBLEMATICA = ['S' => 'Institucional', 'T' => 'Ambiental', 'U' => 'Salud', 'V' => 'Cultural', 'W' => 'Educativa'];
    private const CLASIFICACION = ['Y' => 'Con Ánimo de Lucro', 'Z' => 'Sin Ánimo de Lucro'];
    private const ENFOQUE = ['AA' => 'Discapacidad', 'AB' => 'Cabeza de Hogar', 'AC' => 'Víctimas de Conflicto', 'AD' => 'Habitante de la calle'];

    /** Columnas de texto de la Parte 2 → campo. */
    private const PARTE2 = [
        'AK' => 'item21_categoria', 'AL' => 'item21_bien_servicio', 'AM' => 'item22_descripcion_beneficio', 'AO' => 'item24_gestion_inversion',
        'AQ' => 'item26_sector', 'AR' => 'item26_programa', 'AS' => 'item26_meta_producto', 'AT' => 'item27_nombre_proyecto', 'AU' => 'item28_ods',
        'AV' => 'item28_ddhh', 'AW' => 'item28_pilares_paz', 'AX' => 'item29_politica_publica', 'AY' => 'item30_politica_mipg',
        'BA' => 'item32_acto_tipo', 'BB' => 'item32_numero',
    ];

    private const LARGOS = [
        'nombre' => 255, 'item21_categoria' => 150, 'item21_bien_servicio' => 150, 'item22_descripcion_beneficio' => 500, 'item24_gestion_inversion' => 20,
        'item26_sector' => 150, 'item26_programa' => 255, 'item26_meta_producto' => 800, 'item27_nombre_proyecto' => 255, 'item28_ods' => 150,
        'item28_ddhh' => 255, 'item28_pilares_paz' => 100, 'item29_politica_publica' => 150, 'item30_politica_mipg' => 150, 'item32_acto_tipo' => 10, 'item32_numero' => 50,
    ];

    /**
     * @return array{filas: array<int, array<string, mixed>>, omitidas: array<int, string>, hoja: string}
     *
     * @throws \InvalidArgumentException si el archivo no tiene el formato esperado
     */
    public function leer(string $ruta): array
    {
        $lector = IOFactory::createReaderForFile($ruta);
        if (method_exists($lector, 'setReadDataOnly')) {
            $lector->setReadDataOnly(true);
        }
        $libro = $lector->load($ruta);
        $hoja = $libro->getSheetByName('FO-PDD-19') ?? $libro->getSheet(0);
        $datos = $hoja->toArray(null, true, false, false);
        $nombreHoja = $hoja->getTitle();
        $libro->disconnectWorksheets();
        unset($libro);

        $inicio = null;
        foreach (array_slice($datos, 0, 40, true) as $i => $fila) {
            foreach (array_slice($fila, 0, 6) as $celda) {
                if (is_string($celda) && preg_match(self::ENCABEZADO, $celda)) {
                    $inicio = $i + 1;
                    break 2;
                }
            }
        }
        if ($inicio === null) {
            throw new \InvalidArgumentException('No encuentro el encabezado «Nombres y Apellidos». El archivo debe estar en el formato FO-PDD-19 (Caracterización de Ciudadanía).');
        }

        $filas = [];
        $omitidas = [];
        $sectores = null;
        for ($i = $inicio, $n = count($datos); $i < $n; $i++) {
            $f = $datos[$i];
            $nombre = $this->texto($this->celda($f, 'B'));
            $docCrudo = $this->celda($f, 'D');
            $doc = $this->documento($docCrudo);
            $numeroFila = $i + 1;

            if ($nombre === '' && $doc === '') {
                continue; // fila vacía
            }
            // Filas de encabezado que se repiten (subtítulos como «(1)» o «Número Documento (3)»)
            if (preg_match('/^\(?\d+\)?$/', $nombre) || preg_match('/apellidos|\(\d+(\.\d+)?\)/iu', $nombre) || preg_match('/documento|\(\d+(\.\d+)?\)/iu', $this->texto($docCrudo))) {
                continue;
            }
            if ($doc === '' || $nombre === '') {
                $omitidas[$numeroFila] = $doc === '' ? 'sin número de documento' : 'sin nombre';
                continue;
            }
            if (count($filas) >= self::MAX_FILAS) {
                $omitidas[$numeroFila] = 'supera el máximo de '.self::MAX_FILAS.' filas por archivo';
                continue;
            }

            $tipo = $this->tipoDocumento($this->texto($this->celda($f, 'C')));
            $fila = [
                'fila' => $numeroFila,
                'persona_clave' => mb_substr($tipo.'|'.$doc, 0, 60),
                'nombre' => mb_substr($nombre, 0, self::LARGOS['nombre']),
                'tipo_documento' => $tipo,
                'numero_documento' => mb_substr($doc, 0, 30),
                'municipio' => $this->municipio($this->texto($this->celda($f, 'F'))),
                'zona' => $this->marca($this->celda($f, 'G')) ? 'urbana' : ($this->marca($this->celda($f, 'H')) ? 'rural' : null),
                'genero' => $this->primeraMarca($f, self::GENERO),
                'etnico' => $this->primeraMarca($f, self::ETNICO),
                'clasificacion' => $this->primeraMarca($f, self::CLASIFICACION),
                'problematicas' => $this->lista($f, self::PROBLEMATICA),
                'enfoques' => $this->lista($f, self::ENFOQUE),
                'edad' => $this->entero($this->celda($f, 'AE'), 0, 120),
                'tamano_grupo_familiar' => $this->entero($this->celda($f, 'AF'), 1, 99),
                'item23_fecha_beneficio' => $this->fecha($this->celda($f, 'AN')),
                'item32_fecha' => $this->fecha($this->celda($f, 'BC')),
            ];

            // Sector de la organización: si viene solo el número (como en el formato), se completa con el nombre del catálogo.
            $sector = $this->texto($this->celda($f, 'X'));
            if ($sector !== '' && preg_match('/^\d+$/', $sector)) {
                $sectores ??= CaracterizacionOpcion::where('categoria', 'sector_organizacion')->pluck('valor')->all();
                foreach ($sectores as $valor) {
                    if (str_starts_with($valor, $sector.' - ')) {
                        $sector = $valor;
                        break;
                    }
                }
            }
            $fila['sector'] = $sector !== '' ? mb_substr($sector, 0, 150) : null;

            foreach (self::PARTE2 as $col => $campo) {
                $v = $this->texto($this->celda($f, $col));
                $fila[$campo] = $v !== '' ? mb_substr($v, 0, self::LARGOS[$campo]) : null;
            }
            $filas[] = $fila;
        }

        return ['filas' => $filas, 'omitidas' => $omitidas, 'hoja' => $nombreHoja];
    }

    private function celda(array $fila, string $col)
    {
        return $fila[Coordinate::columnIndexFromString($col) - 1] ?? null;
    }

    private function texto($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    /** Hay una marca (X, 1, «sí»…) en la celda. */
    private function marca($v): bool
    {
        $t = mb_strtolower($this->texto($v));

        return $t !== '' && ! in_array($t, ['0', 'no', 'n', 'false', '-', 'x/x'], true);
    }

    private function primeraMarca(array $fila, array $opciones): ?string
    {
        foreach ($opciones as $col => $valor) {
            if ($this->marca($this->celda($fila, $col))) {
                return $valor;
            }
        }

        return null;
    }

    /** «|Salud|Ambiental|» (o null) para filtrar con LIKE. */
    private function lista(array $fila, array $opciones): ?string
    {
        $marcadas = [];
        foreach ($opciones as $col => $valor) {
            if ($this->marca($this->celda($fila, $col))) {
                $marcadas[] = $valor;
            }
        }

        return $marcadas ? '|'.implode('|', $marcadas).'|' : null;
    }

    private function documento($v): string
    {
        if (is_float($v)) {
            $v = sprintf('%.0f', $v);
        }

        return mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $v) ?? '', 0, 30);
    }

    private function tipoDocumento(string $t): string
    {
        $limpio = mb_strtoupper(str_replace([' ', '.'], '', $t));

        return match ($limpio) {
            'CC', 'CEDULA', 'CÉDULA' => 'C.C',
            'TI' => 'T.I',
            'RC' => 'R.C',
            'CE' => 'C.E',
            'PASAPORTE', 'PA' => 'PASAPORTE',
            'NIT' => 'NIT',
            default => $limpio !== '' ? mb_substr($limpio, 0, 12) : 'C.C',
        };
    }

    private function municipio(string $m): ?string
    {
        if ($m === '') {
            return null;
        }
        $m = strtr(mb_strtoupper($m), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return mb_substr($m, 0, 60);
    }

    private function entero($v, int $min, int $max): ?int
    {
        if ($v === null || ! is_numeric($v)) {
            return null;
        }
        $n = (int) round((float) $v);

        return $n >= $min && $n <= $max ? $n : null;
    }

    /** Fecha del Excel: número de serie, «31/12/2026», «2026-12-31»… → «Y-m-d» o null. */
    private function fecha($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            if (is_numeric($v) && $v > 20000 && $v < 80000) {
                return Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
            }
            $t = $this->texto($v);
            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $t, $m)) {
                $anio = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

                return checkdate((int) $m[2], (int) $m[1], $anio) ? sprintf('%04d-%02d-%02d', $anio, $m[2], $m[1]) : null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $t)) {
                return substr($t, 0, 10);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
