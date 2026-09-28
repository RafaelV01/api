<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogActividad extends Model
{
    protected $table = 'logs_actividad';

    // Los logs son inmutables: solo se maneja created_at (sin updated_at).
    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'usuario_email',
        'accion',
        'entidad_tipo',
        'entidad_id',
        'datos_antes',
        'datos_despues',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'datos_antes' => 'array',
        'datos_despues' => 'array',
        'created_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
