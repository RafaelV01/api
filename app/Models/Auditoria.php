<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    use HasFactory;

    protected $table = 'auditoria';

    // Le decimos a Laravel que este modelo solo gestiona `created_at`
    const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'entidad',
        'entidad_id',
        'accion',
        'detalle',
        'ip',
        'user_agent',
    ];

    /**
     * Convierte la columna 'detalle' de JSON a array automáticamente.
     */
    protected $casts = [
        'detalle' => 'array',
    ];

    /**
     * Relación: Un registro de auditoría es realizado por un actor (usuario).
     */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}