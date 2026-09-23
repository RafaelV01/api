<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionSecretaria extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_secretarias';
    protected $fillable = ['nombre', 'activo'];

    public function dependencias()
    {
        return $this->hasMany(CaracterizacionDependencia::class, 'secretaria_id');
    }

    public function perfiles()
    {
        return $this->hasMany(CaracterizacionPerfil::class, 'secretaria_id');
    }

    public function actividades()
    {
        return $this->hasMany(CaracterizacionActividad::class, 'secretaria_id');
    }
}
