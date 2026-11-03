<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firmas de los ciudadanos como ARCHIVOS (storage/app/private/firmas/{actividad}/{uuid}.jpg) en lugar de texto base64
 * dentro de la base de datos: la base de datos pesa mucho menos y los PDF/Excel cargan más rápido.
 * Al guardar se valida que sea una imagen real y se reduce a un tamaño razonable para una firma.
 */
class FirmasCiudadano
{
    public const MAX_BYTES = 700 * 1024;   // tope de la firma recibida
    public const ANCHO_MAX = 480;
    public const ALTO_MAX = 180;

    /** ¿Es un data URI de imagen JPEG/PNG de tamaño razonable? (para validar lo que envía el navegador) */
    /**
     * Firma electrónica por consentimiento (alternativa accesible al lienzo): una imagen con el nombre, el documento y la fecha,
     * para que salga en el PDF/Excel como cualquier otra firma. Devuelve un data URI PNG.
     */
    public function electronica(string $nombre, string $tipoDocumento, string $numero, \DateTimeInterface $fecha): string
    {
        $limpio = function (string $s): string {
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

            return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        };
        $lineas = [
            'FIRMADO ELECTRONICAMENTE',
            mb_substr($limpio($nombre), 0, 44),
            mb_substr($limpio($tipoDocumento).' '.$limpio($numero), 0, 44),
            $fecha->format('d/m/Y H:i'),
        ];

        $im = imagecreatetruecolor(self::ANCHO_MAX, 110);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $tinta = imagecolorallocate($im, 20, 20, 90);
        foreach ($lineas as $i => $texto) {
            imagestring($im, $i === 0 ? 3 : 4, 10, 8 + $i * 24, $texto, $tinta);
        }
        imagerectangle($im, 1, 1, self::ANCHO_MAX - 2, 108, imagecolorallocate($im, 160, 160, 190));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function esValida(?string $dataUri): bool
    {
        return $this->decodificar($dataUri ?? '') !== null;
    }

    /**
     * Guarda la firma y devuelve la ruta relativa dentro del disco `local`.
     *
     * @throws \InvalidArgumentException si no es una imagen válida
     */
    public function guardar(string $dataUri, int $actividadId): string
    {
        $binario = $this->decodificar($dataUri);
        if ($binario === null) {
            throw new \InvalidArgumentException('La firma no es una imagen válida.');
        }

        $ruta = 'firmas/'.$actividadId.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($ruta, $this->optimizar($binario));

        return $ruta;
    }

    /** Bytes de la firma guardada (null si el archivo no existe). */
    public function leer(?string $ruta): ?string
    {
        return $ruta && Storage::disk('local')->exists($ruta) ? Storage::disk('local')->get($ruta) : null;
    }

    /** Decodifica un data URI de imagen; null si no es válido. */
    public function decodificar(string $dataUri): ?string
    {
        if (! preg_match('#^data:image/(jpeg|jpg|png);base64,(.+)$#s', $dataUri, $m)) {
            return null;
        }
        $binario = base64_decode($m[2], true);
        if ($binario === false || $binario === '' || strlen($binario) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($binario);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) {
            return null;
        }

        return $binario;
    }

    /** Reduce la firma a un máximo de 480×180 px, sobre fondo blanco, en JPEG: pesa unos pocos KB. */
    public function optimizar(string $binario): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $binario;
        }
        $origen = @imagecreatefromstring($binario);
        if (! $origen) {
            return $binario;
        }
        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $escala = min(self::ANCHO_MAX / $ancho, self::ALTO_MAX / $alto, 1);
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        ob_start();
        imagejpeg($lienzo, null, 78);
        $jpeg = (string) ob_get_clean();
        imagedestroy($origen);
        imagedestroy($lienzo);

        return $jpeg !== '' ? $jpeg : $binario;
    }
}
