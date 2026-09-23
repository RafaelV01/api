<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionDependencia extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_dependencias';
    protected $fillable = ['secretaria_id', 'nombre', 'activo'];

    public function secretaria()
    {
        return $this->belongsTo(CaracterizacionSecretaria::class, 'secretaria_id');
    }

    public function perfiles()
    {
        return $this->hasMany(CaracterizacionPerfil::class, 'dependencia_id');
    }

    public function actividades()
    {
        return $this->hasMany(CaracterizacionActividad::class, 'dependencia_id');
    }
}
