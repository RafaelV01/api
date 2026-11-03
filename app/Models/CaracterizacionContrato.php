<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionContrato extends Model
{
    use Auditable;

    protected $table = 'caracterizacion_contratos';
    protected $fillable = ['usuario_id', 'numero_contrato', 'fecha_inicio', 'fecha_fin', 'activo'];
    protected $casts = ['fecha_inicio' => 'date', 'fecha_fin' => 'date', 'activo' => 'boolean'];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Vigente hoy: activo y dentro de sus fechas (la fecha final cuenta completa). */
    public function estaVigente(): bool
    {
        $hoy = now()->startOfDay();

        return $this->activo && $this->fecha_inicio->startOfDay()->lte($hoy) && $this->fecha_fin->startOfDay()->gte($hoy);
    }

    /** vigente | por_iniciar | vencido */
    public function estado(): string
    {
        $hoy = now()->startOfDay();
        if (! $this->activo) {
            return 'vencido';
        }

        return match (true) {
            $this->fecha_inicio->startOfDay()->gt($hoy) => 'por_iniciar',
            $this->fecha_fin->startOfDay()->lt($hoy) => 'vencido',
            default => 'vigente',
        };
    }

    /**
     * Motivo por el que un usuario no puede entrar por su contrato, o null si puede. Solo aplica a contratistas con al menos
     * un contrato registrado: quien no tiene contratos cargados (usuarios anteriores, supervisores, administradores) no se bloquea.
     */
    public static function motivoSinAcceso(User $usuario): ?string
    {
        if ($usuario->caracterizacionPerfil?->rol !== CaracterizacionPerfil::ROL_CONTRATISTA) {
            return null;
        }
        $contratos = self::where('usuario_id', $usuario->id)->get();
        if ($contratos->isEmpty() || $contratos->contains(fn (self $c) => $c->estaVigente())) {
            return null;
        }

        $proximo = $contratos->first(fn (self $c) => $c->estado() === 'por_iniciar');
        if ($proximo) {
            return 'Tu contrato inicia el '.$proximo->fecha_inicio->format('d/m/Y').'. Hasta entonces no tienes acceso a la plataforma.';
        }

        return 'Tu contrato terminó el '.$contratos->max('fecha_fin')->format('d/m/Y').' y el acceso a la plataforma está deshabilitado. Comunícate con tu supervisor o referente.';
    }
}
