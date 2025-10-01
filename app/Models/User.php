<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'usuarios';

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'nombre_completo',
        'cargo',
        'dependencia',
        'telefono',
        'email',
        'password',
        'rol_id',
    ];

    /**
     * Los atributos que deben ocultarse para la serialización.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Obtiene el rol al que pertenece el usuario.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    /**
     * Obtiene las reuniones que este usuario ha creado.
     */
    public function reunionesCreadas()
    {
        return $this->hasMany(Reunion::class, 'creador_id');
    }

    /**
     * Obtiene todas las asistencias registradas por este usuario.
     */
    public function asistencias()
    {
        return $this->hasMany(Asistente::class, 'usuario_id');
    }

    /**
     * Obtiene todos los códigos de reunión creados por este usuario.
     */
    public function codigosCreados()
    {
        return $this->hasMany(CodigoReunion::class, 'creado_por');
    }
}