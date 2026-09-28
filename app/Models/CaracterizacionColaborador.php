<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionColaborador extends Model
{
    use HasFactory, Auditable;

    protected $table = 'caracterizacion_colaboradores';

    // No hay concepto de "updated_at" aquí, solo created_at/responded_at explícitos
    // (ver la migración), así que se maneja el timestamp de creación a mano.
    public $timestamps = false;

    protected $fillable = [
        'actividad_id', 'usuario_id', 'invitado_por', 'estado', 'created_at', 'responded_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // Estados válidos — varchar en BD a propósito, validado aquí, no en un ENUM de MySQL
    // (mismo patrón que CaracterizacionPerfil::ROLES).
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ACEPTADO = 'aceptado';
    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADOS = [self::ESTADO_PENDIENTE, self::ESTADO_ACEPTADO, self::ESTADO_RECHAZADO];

    public function actividad()
    {
        return $this->belongsTo(CaracterizacionActividad::class, 'actividad_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function invitadoPor()
    {
        return $this->belongsTo(User::class, 'invitado_por');
    }
}
