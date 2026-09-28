<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionAsistente extends Model
{
    use HasFactory, Auditable;

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
        // Parte 2 (ítems 20–32) ya NO vive aquí — ver CaracterizacionSeguimiento: ahora
        // se completa una sola vez por actividad, no por cada ciudadano registrado.
    ];

    /**
     * Replica exactamente la fórmula de la hoja FO-PDD-19 del Excel fuente:
     * =+IF(edad=0," ",IF(edad<=5,"Primera infancia",IF(edad<=11,"Infancia",
     *   IF(edad<=17,"Adolescencia",IF(edad<=28,"Jóvenes",IF(edad<=60,"Adultos",
     *   IF(edad<=120,"Mayores","")))))))
     * Nota: la pestaña INSTRUCTIVO del mismo Excel describe el rango de "Adultos" como 29–59 y
     * "Mayores" como 60+, lo cual no coincide con esta fórmula real (que incluye la edad 60 en
     * "Adultos"). Se implementa la fórmula que efectivamente corre en la hoja, no el texto.
     *
     * Antes esto se guardaba en item25_grupo_etareo de esta misma tabla, calculado en un
     * booted() hook al guardar. Desde que Parte 2 (ítems 20–32) pasó a ser por actividad
     * (CaracterizacionSeguimiento) en vez de por ciudadano, un solo "grupo etáreo" por
     * actividad ya no tiene sentido — distintos ciudadanos de la misma actividad pueden
     * tener edades distintas — así que ya no se persiste en ningún lado: se calcula al
     * vuelo, por fila, en el momento de renderizar el PDF/Excel, a partir del item15_edad
     * propio de cada asistente. Se conserva público y estático aquí para que el código de
     * render lo siga usando por fila.
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

    public function problematicas()
    {
        return $this->hasMany(CaracterizacionAsistenteProblematica::class, 'asistente_id');
    }

    public function enfoques()
    {
        return $this->hasMany(CaracterizacionAsistenteEnfoque::class, 'asistente_id');
    }
}
