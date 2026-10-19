<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Exportación pedida por un usuario y generada en segundo plano (ver `caracterizacion:procesar-exportaciones`). */
class CaracterizacionExportacion extends Model
{
    public const TIPOS = ['consolidado', 'variables'];

    protected $table = 'caracterizacion_exportaciones';

    protected $fillable = [
        'usuario_id', 'tipo', 'parametros', 'estado', 'archivo', 'nombre_descarga', 'bytes', 'error', 'iniciado_en', 'terminado_en',
    ];

    protected $casts = [
        'parametros' => 'array',
        'iniciado_en' => 'datetime',
        'terminado_en' => 'datetime',
    ];
}
