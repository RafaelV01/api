<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CodigoReunion extends Model
{
    use HasFactory;

    protected $table = 'codigos_reunion';

    protected $fillable = [
        'reunion_id',
        'codigo',
        'expira_en',
        'activo',
        'creado_por',
    ];

    /**
     * Relación: Un código pertenece a una reunión.
     */
    public function reunion()
    {
        return $this->belongsTo(Reunion::class, 'reunion_id');
    }

    /**
     * Relación: Un código fue creado por un usuario.
     */
    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}