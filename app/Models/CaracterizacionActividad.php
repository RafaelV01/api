<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionActividad extends Model
{
    use HasFactory, Auditable;

    protected $table = 'caracterizacion_actividades';

    protected $fillable = [
        'codigo', 'slug_acceso', 'tema', 'fecha', 'hora', 'quien_dirige_expone',
        'firma_expositor_base64', 'firma_expositor_hash', 'municipio', 'departamento',
        'tipo_evento', 'otro_evento_detalle', 'creador_id', 'dependencia_id', 'secretaria_id',
        'estado', 'estado_aprobacion', 'aprobado_por', 'fecha_aprobacion', 'observacion_aprobacion',
        'enviada_en', 'referente_aprobo_por', 'referente_aprobo_en',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_aprobacion' => 'datetime',
        'enviada_en' => 'datetime',
        'referente_aprobo_en' => 'datetime',
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
     * Parte 2 (ítems 20–32, "seguimiento") — una sola fila por actividad, ya no
     * una por ciudadano.
     */
    public function seguimiento()
    {
        return $this->hasOne(CaracterizacionSeguimiento::class, 'actividad_id')->oldestOfMany();
    }

    /** Seguimiento de cada contratista de la reunión (el del creador y el de cada colaborador). */
    public function seguimientos()
    {
        return $this->hasMany(CaracterizacionSeguimiento::class, 'actividad_id');
    }

    // Estado de la revisión: borrador → pendiente (con el referente) → revision_supervisor → aprobada; «rechazada» = devuelta al contratista.
    public const BORRADOR = 'borrador';
    public const PENDIENTE = 'pendiente';
    public const REVISION_SUPERVISOR = 'revision_supervisor';
    public const APROBADA = 'aprobada';
    public const RECHAZADA = 'rechazada';

    /**
     * Usuarios invitados (pendientes, aceptados o rechazados) a ayudar a completar
     * la Parte 2 de esta actividad, además de su creador.
     */
    public function colaboradores()
    {
        return $this->hasMany(CaracterizacionColaborador::class, 'actividad_id');
    }

    /**
     * Restringe la consulta a lo que el usuario autenticado puede ver según su
     * perfil de caracterización: contratista ve solo lo propio, secretaría ve
     * todo lo de su secretaría, administrador ve todo.
     */
    public function scopeVisiblePara(Builder $query, User $user): Builder
    {
        $perfil = $user->caracterizacionPerfil;

        // Sin perfil de Caracterización (o perfil desactivado) = no ve nada, nunca
        // "ve todo" — antes `!$perfil` caía en el mismo return que administrador.
        if (!$perfil || !$perfil->activo) {
            return $query->whereRaw('1 = 0');
        }

        if ($perfil->esGlobal()) {
            return $query;
        }

        // Supervisor: toda su secretaría. Referente: su dependencia. Un perfil sin la secretaría/dependencia asignada no ve nada.
        if ($perfil->rol === CaracterizacionPerfil::ROL_SUPERVISOR) {
            return $perfil->secretaria_id ? $query->where('secretaria_id', $perfil->secretaria_id) : $query->whereRaw('1 = 0');
        }

        if ($perfil->rol === CaracterizacionPerfil::ROL_REFERENTE) {
            return $perfil->dependencia_id ? $query->where('dependencia_id', $perfil->dependencia_id) : $query->whereRaw('1 = 0');
        }

        return $query->where('creador_id', $user->id);
    }
}
