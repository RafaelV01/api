<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaracterizacionAsistenteProblematica extends Model
{
    protected $table = 'caracterizacion_asistente_problematicas';
    protected $fillable = ['asistente_id', 'valor'];

    public function asistente()
    {
        return $this->belongsTo(CaracterizacionAsistente::class, 'asistente_id');
    }
}
