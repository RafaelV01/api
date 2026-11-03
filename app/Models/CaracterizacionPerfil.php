<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionPerfil extends Model
{
    use HasFactory, Auditable;

    protected $table = 'caracterizacion_perfiles';
    protected $fillable = ['usuario_id', 'rol', 'secretaria_id', 'dependencia_id', 'activo'];

    // Roles válidos — varchar en BD a propósito, validado aquí, no en un ENUM de MySQL.
    // Jerarquía: super admin → administrador → supervisor (uno por secretaría) → referente (uno por dependencia) → contratista.
    public const ROL_SUPER_ADMIN = 'super_admin';
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_SUPERVISOR = 'supervisor';
    public const ROL_REFERENTE = 'referente';
    public const ROL_CONTRATISTA = 'contratista';

    public const ROLES = [self::ROL_SUPER_ADMIN, self::ROL_ADMINISTRADOR, self::ROL_SUPERVISOR, self::ROL_REFERENTE, self::ROL_CONTRATISTA];

    /** Roles que ven y gestionan todo el sistema. */
    public const ROLES_GLOBALES = [self::ROL_SUPER_ADMIN, self::ROL_ADMINISTRADOR];

    /** Nombre para mostrar de cada rol. */
    public const ETIQUETAS = [
        self::ROL_SUPER_ADMIN => 'Super administrador', self::ROL_ADMINISTRADOR => 'Administrador', self::ROL_SUPERVISOR => 'Supervisor',
        self::ROL_REFERENTE => 'Referente', self::ROL_CONTRATISTA => 'Contratista',
    ];

    public function esGlobal(): bool
    {
        return in_array($this->rol, self::ROLES_GLOBALES, true);
    }

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
