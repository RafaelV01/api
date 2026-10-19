<?php

namespace App\Support;

/**
 * Código DANE (departamento 85 + municipio) de los 19 municipios de Casanare, para el
 * ítem 20 del FO-PDD-19 («Código Departamento / Municipio»). Se asigna solo a partir del
 * municipio diligenciado, comparando sin tildes ni mayúsculas ("Orocué" = "OROCUE").
 */
class CodigoDane
{
    private const CODIGOS = [
        'YOPAL' => '85001',
        'AGUAZUL' => '85010',
        'CHAMEZA' => '85015',
        'HATO COROZAL' => '85125',
        'LA SALINA' => '85136',
        'MANI' => '85139',
        'MONTERREY' => '85162',
        'NUNCHIA' => '85225',
        'OROCUE' => '85230',
        'PAZ DE ARIPORO' => '85250',
        'PORE' => '85263',
        'RECETOR' => '85279',
        'SABANALARGA' => '85300',
        'SACAMA' => '85315',
        'SAN LUIS DE PALENQUE' => '85325',
        'TAMARA' => '85400',
        'TAURAMENA' => '85410',
        'TRINIDAD' => '85430',
        'VILLANUEVA' => '85440',
    ];

    public static function normalizar(?string $texto): string
    {
        $texto = mb_strtoupper(trim((string) $texto), 'UTF-8');
        $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/', ' ', $texto);
    }

    /** Código DANE del municipio, o null si no es un municipio de Casanare. */
    public static function deMunicipio(?string $municipio): ?string
    {
        return self::CODIGOS[self::normalizar($municipio)] ?? null;
    }

    /** @return array<string,string> nombre normalizado => código (para pruebas o para el frontend). */
    public static function todos(): array
    {
        return self::CODIGOS;
    }
}
