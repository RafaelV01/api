<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionPerfil extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_perfiles';
    protected $fillable = ['usuario_id', 'rol', 'secretaria_id', 'dependencia_id', 'activo'];

    // Roles válidos — varchar en BD a propósito, validado aquí, no en un ENUM de MySQL.
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_SECRETARIA = 'secretaria';
    public const ROL_CONTRATISTA = 'contratista';

    public const ROLES = [self::ROL_ADMINISTRADOR, self::ROL_SECRETARIA, self::ROL_CONTRATISTA];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function secretaria()
    {
        return $this->belongsTo(CaracterizacionSecretaria::class, 'secretaria_id');
    }

    public function dependencia()
    {
        return $this->belongsTo(CaracterizacionDependencia::class, 'dependencia_id');
    }
}
