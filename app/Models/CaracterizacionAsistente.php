<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionAsistente extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_asistentes';

    protected $fillable = [
        'actividad_id',
        // Parte 1 — la llena el ciudadano
        'item1_nombre', 'item2_tipo_documento', 'item3_numero_documento',
        'item4_cargo_barrio_vereda', 'item5_municipio', 'item6_zona',
        'item7_ubicacion_tipo', 'item7_ubicacion_detalle', 'item8_contacto_tipo',
        'item8_contacto_valor', 'item9_genero', 'item10_etnico',
        'item12_sector_organizacion', 'item13_clasificacion_organizacion',
        'item15_edad', 'item16_tamano_grupo_familiar', 'item17_canal_comunicacion',
        'item18_idioma_lengua_dialecto', 'firma_ciudadano_base64', 'firma_ciudadano_hash',
        'ip_origen', 'user_agent',
        // Parte 2 — la completa después el contratista
        'item20_codigo_dane', 'item21_categoria', 'item21_bien_servicio',
        'item22_descripcion_beneficio', 'item23_fecha_beneficio', 'item24_gestion_inversion',
        'item26_sector', 'item26_programa', 'item26_meta_producto', 'item27_nombre_proyecto',
        'item28_ods', 'item28_ddhh', 'item28_pilares_paz', 'item29_politica_publica',
        'item30_politica_mipg', 'item31_total_beneficiarios', 'item32_acto_tipo',
        'item32_numero', 'item32_fecha', 'part2_completado_por', 'part2_completado_en',
    ];

    // item25_grupo_etareo queda fuera de $fillable a propósito: es calculado, nunca viene del cliente.
    protected $guarded = ['id', 'item25_grupo_etareo'];

    protected $casts = [
        'item23_fecha_beneficio' => 'date',
        'item32_fecha' => 'date',
        'part2_completado_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (CaracterizacionAsistente $asistente) {
            $asistente->item25_grupo_etareo = self::calcularGrupoEtareo($asistente->item15_edad);
        });
    }

    /**
     * Replica exactamente la fórmula de la hoja FO-PDD-19 del Excel fuente:
     * =+IF(edad=0," ",IF(edad<=5,"Primera infancia",IF(edad<=11,"Infancia",
     *   IF(edad<=17,"Adolescencia",IF(edad<=28,"Jóvenes",IF(edad<=60,"Adultos",
     *   IF(edad<=120,"Mayores","")))))))
     * Nota: la pestaña INSTRUCTIVO del mismo Excel describe el rango de "Adultos" como 29–59 y
     * "Mayores" como 60+, lo cual no coincide con esta fórmula real (que incluye la edad 60 en
     * "Adultos"). Se implementa la fórmula que efectivamente corre en la hoja, no el texto.
     */
    public static function calcularGrupoEtareo(?int $edad): string
    {
        if ($edad === null || $edad === 0) {
            return '';
        }

        return match (true) {
            $edad <= 5 => 'Primera infancia',
            $edad <= 11 => 'Infancia',
            $edad <= 17 => 'Adolescencia',
            $edad <= 28 => 'Jóvenes',
            $edad <= 60 => 'Adultos',
            $edad <= 120 => 'Mayores',
            default => '',
        };
    }

    public function actividad()
    {
        return $this->belongsTo(CaracterizacionActividad::class, 'actividad_id');
    }

    public function part2CompletadoPor()
    {
        return $this->belongsTo(User::class, 'part2_completado_por');
    }

    public function problematicas()
    {
        return $this->hasMany(CaracterizacionAsistenteProblematica::class, 'asistente_id');
    }

    public function enfoques()
    {
        return $this->hasMany(CaracterizacionAsistenteEnfoque::class, 'asistente_id');
    }
}
