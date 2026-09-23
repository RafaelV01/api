<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaracterizacionAsistenteEnfoque extends Model
{
    protected $table = 'caracterizacion_asistente_enfoques';
    protected $fillable = ['asistente_id', 'valor'];

    public function asistente()
    {
        return $this->belongsTo(CaracterizacionAsistente::class, 'asistente_id');
    }
}
