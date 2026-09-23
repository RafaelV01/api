<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionActividad extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_actividades';

    protected $fillable = [
        'codigo', 'slug_acceso', 'tema', 'fecha', 'hora', 'quien_dirige_expone',
        'firma_expositor_base64', 'firma_expositor_hash', 'municipio', 'departamento',
        'tipo_evento', 'otro_evento_detalle', 'creador_id', 'dependencia_id', 'secretaria_id',
        'estado', 'estado_aprobacion', 'aprobado_por', 'fecha_aprobacion', 'observacion_aprobacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_aprobacion' => 'datetime',
    ];

    public function creador()
    {
        return $this->belongsTo(User::class, 'creador_id');
    }

    public function dependencia()
    {
        return $this->belongsTo(CaracterizacionDependencia::class, 'dependencia_id');
    }

    public function secretaria()
    {
        return $this->belongsTo(CaracterizacionSecretaria::class, 'secretaria_id');
    }

    public function aprobadoPor()
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function asistentes()
    {
        return $this->hasMany(CaracterizacionAsistente::class, 'actividad_id');
    }

    /**
     * Restringe la consulta a lo que el usuario autenticado puede ver según su
     * perfil de caracterización: contratista ve solo lo propio, secretaría ve
     * todo lo de su secretaría, administrador ve todo.
     */
    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        $perfil = $user->caracterizacionPerfil;

        if (!$perfil || $perfil->rol === CaracterizacionPerfil::ROL_ADMINISTRADOR) {
            return $query;
        }

        if ($perfil->rol === CaracterizacionPerfil::ROL_SECRETARIA) {
            return $query->where('secretaria_id', $perfil->secretaria_id);
        }

        return $query->where('creador_id', $user->id);
    }
}
