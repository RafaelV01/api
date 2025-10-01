<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AjustesReunion extends Model
{
    use HasFactory;

    protected $table = 'ajustes_reunion';

    protected $fillable = [
        'reunion_id',
        'mostrar_lista_a_participantes',
        'permitir_autoregistro',
        'requerir_login_para_unirse',
        'permitir_edicion_por_creador',
    ];

    /**
     * Relación: Estos ajustes pertenecen a una reunión.
     */
    public function reunion()
    {
        return $this->belongsTo(Reunion::class, 'reunion_id');
    }
}