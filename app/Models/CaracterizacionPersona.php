<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciudadano único (tipo + número de documento) con sus datos más recientes. Sus participaciones en reuniones
 * son filas de CaracterizacionAsistente, que conservan lo declarado en cada una.
 */
class CaracterizacionPersona extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_personas';

    protected $fillable = [
        'persona_clave', 'tipo_documento', 'numero_documento', 'nombre', 'municipio', 'zona', 'ubicacion_tipo', 'ubicacion_detalle',
        'contacto', 'genero', 'etnico', 'sector_organizacion', 'clasificacion_organizacion', 'edad', 'tamano_grupo_familiar',
        'cargo_barrio_vereda', 'participaciones', 'ultima_participacion_en',
    ];

    protected $casts = ['ultima_participacion_en' => 'datetime'];

    /** Misma regla que la columna `persona_clave` de caracterizacion_asistentes: TIPO en mayúsculas + '|' + número, sin espacios sobrantes. */
    public static function clave(?string $tipo, ?string $numero): string
    {
        return mb_strtoupper(trim((string) $tipo)).'|'.trim((string) $numero);
    }

    public function asistentes()
    {
        return $this->hasMany(CaracterizacionAsistente::class, 'persona_id');
    }
}
