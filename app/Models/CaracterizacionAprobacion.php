<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaracterizacionAprobacion extends Model
{
    const UPDATED_AT = null;

    protected $table = 'caracterizacion_aprobaciones';
    protected $fillable = [
        'tipo_objetivo', 'objetivo_id', 'accion', 'actor_id', 'observacion', 'actividades_afectadas',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
