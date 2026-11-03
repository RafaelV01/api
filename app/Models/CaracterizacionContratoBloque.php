<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Información de seguimiento (ítems 21–32) que sale del contrato del contratista. Un contrato tiene de 1 a 3 bloques (casos con varias
 * actividades). Al crear la reunión se COPIA al seguimiento de la reunión: si el contrato cambia después, las reuniones anteriores
 * conservan lo del contrato anterior. No incluye: 20 (sale del municipio), 25 (de la edad) ni 31 (número de asistentes).
 */
class CaracterizacionContratoBloque extends Model
{
    use Auditable;

    public const MAX_POR_CONTRATO = 3;

    protected $table = 'caracterizacion_contrato_bloques';

    public const CAMPOS = [
        'item21_categoria', 'item21_bien_servicio', 'item22_descripcion_beneficio', 'item23_fecha_beneficio', 'item24_gestion_inversion',
        'item26_sector', 'item26_programa', 'item26_meta_producto', 'item27_nombre_proyecto', 'item28_ods', 'item28_ddhh', 'item28_pilares_paz',
        'item29_politica_publica', 'item30_politica_mipg', 'item32_acto_tipo', 'item32_numero', 'item32_fecha',
    ];

    protected $fillable = ['contrato_id', 'orden', 'nombre', ...self::CAMPOS];
    protected $casts = ['item23_fecha_beneficio' => 'date', 'item32_fecha' => 'date'];

    public function contrato()
    {
        return $this->belongsTo(CaracterizacionContrato::class, 'contrato_id');
    }

    /** Reglas de validación de un bloque (mismas del seguimiento de una reunión), con prefijo opcional para listas (ej. «bloques.*.»). */
    public static function reglas(string $prefijo = ''): array
    {
        $opcion = fn (string $categoria) => Rule::exists('caracterizacion_opciones', 'valor')->where('categoria', $categoria)->where('activo', true);

        return [
            $prefijo.'nombre' => 'nullable|string|max:80',
            $prefijo.'item21_categoria' => 'nullable|string|max:150',
            $prefijo.'item21_bien_servicio' => 'nullable|string|max:150',
            $prefijo.'item22_descripcion_beneficio' => 'nullable|string|max:500',
            $prefijo.'item23_fecha_beneficio' => 'nullable|date',
            $prefijo.'item24_gestion_inversion' => 'nullable|in:Gestión,Inversión',
            $prefijo.'item26_sector' => 'nullable|string|max:150',
            $prefijo.'item26_programa' => 'nullable|string|max:255',
            $prefijo.'item26_meta_producto' => 'nullable|string|max:800',
            $prefijo.'item27_nombre_proyecto' => 'nullable|string|max:255',
            $prefijo.'item28_ods' => ['nullable', 'string', $opcion('ods')],
            $prefijo.'item28_ddhh' => ['nullable', 'string', $opcion('ddhh')],
            $prefijo.'item28_pilares_paz' => ['nullable', 'string', $opcion('pilares_paz')],
            $prefijo.'item29_politica_publica' => ['nullable', 'string', $opcion('politica_publica')],
            $prefijo.'item30_politica_mipg' => ['nullable', 'string', $opcion('politica_mipg')],
            $prefijo.'item32_acto_tipo' => ['nullable', 'string', $opcion('acto_tipo')],
            $prefijo.'item32_numero' => ['nullable', 'string', 'max:50', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} .\-\/]*$/u'],
            $prefijo.'item32_fecha' => 'nullable|date',
        ];
    }

    /**
     * Copia este bloque al seguimiento de la reunión para ese contratista (crea o reemplaza el suyo). El código DANE sale del municipio
     * de la reunión. Devuelve el seguimiento resultante.
     */
    public function copiarA(CaracterizacionActividad $actividad, int $usuarioId): CaracterizacionSeguimiento
    {
        $datos = [];
        foreach (self::CAMPOS as $campo) {
            $datos[$campo] = $this->{$campo};
        }

        return CaracterizacionSeguimiento::updateOrCreate(
            ['actividad_id' => $actividad->id, 'usuario_id' => $usuarioId],
            $datos + [
                'item20_codigo_dane' => \App\Support\CodigoDane::deMunicipio($actividad->municipio),
                'contrato_bloque_id' => $this->id,
                'completado_por' => $usuarioId,
                'completado_en' => now(),
            ]
        );
    }

    /** Bloques del contrato vigente (o el más reciente) del usuario; vacío si no tiene contrato o bloques. */
    public static function delContratoActual(int $usuarioId)
    {
        $contrato = CaracterizacionContrato::where('usuario_id', $usuarioId)->orderByDesc('fecha_fin')->first();

        return $contrato ? $contrato->bloques()->orderBy('orden')->get() : collect();
    }
}
