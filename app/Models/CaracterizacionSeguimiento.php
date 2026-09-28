<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionSeguimiento extends Model
{
    use HasFactory, Auditable;

    protected $table = 'caracterizacion_seguimientos';

    protected $fillable = [
        'actividad_id',
        // Parte 2 (ítems 20–32) — una sola vez por actividad, la completa el
        // contratista creador o un colaborador invitado y aceptado.
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

    public function actividad()
    {
        return $this->belongsTo(CaracterizacionActividad::class, 'actividad_id');
    }

    public function completadoPor()
    {
        return $this->belongsTo(User::class, 'completado_por');
    }
}
