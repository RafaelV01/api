<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Parte 2 (ítems 20–32) propia de UN ciudadano; sobrescribe al seguimiento general
 * de la actividad (CaracterizacionSeguimiento) solo para esa persona.
 */
class CaracterizacionSeguimientoAsistente extends Model
{
    use HasFactory, Auditable;

    protected $table = 'caracterizacion_seguimiento_asistentes';

    protected $fillable = [
        'asistente_id',
        'item20_codigo_dane', 'item21_categoria', 'item21_bien_servicio',
        'item22_descripcion_beneficio', 'item23_fecha_beneficio', 'item24_gestion_inversion',
        'item26_sector', 'item26_programa', 'item26_meta_producto', 'item27_nombre_proyecto',
        'item28_ods', 'item28_ddhh', 'item28_pilares_paz', 'item29_politica_publica',
        'item30_politica_mipg', 'item31_total_beneficiarios', 'item32_acto_tipo',
        'item32_numero', 'item32_fecha', 'completado_por', 'completado_en',
    ];

    protected $casts = [
        'item23_fecha_beneficio' => 'date',
        'item32_fecha' => 'date',
        'completado_en' => 'datetime',
    ];

    public function asistente()
    {
        return $this->belongsTo(CaracterizacionAsistente::class, 'asistente_id');
    }

    public function completadoPor()
    {
        return $this->belongsTo(User::class, 'completado_por');
    }
}
