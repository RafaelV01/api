<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/** Aviso dentro de la aplicación para un usuario (la campana del encabezado). */
class CaracterizacionNotificacion extends Model
{
    public $timestamps = false;

    protected $table = 'caracterizacion_notificaciones';

    protected $fillable = ['usuario_id', 'tipo', 'titulo', 'mensaje', 'url', 'leida_en', 'created_at'];

    protected $casts = ['leida_en' => 'datetime', 'created_at' => 'datetime'];

    /**
     * Crea un aviso. Nunca debe romper la acción que lo origina (aprobar, invitar…): si algo falla, solo se registra.
     */
    public static function enviar(?int $usuarioId, string $tipo, string $titulo, ?string $mensaje = null, ?string $url = null): void
    {
        if (! $usuarioId) {
            return;
        }
        try {
            static::create([
                'usuario_id' => $usuarioId, 'tipo' => $tipo, 'titulo' => mb_substr($titulo, 0, 160),
                'mensaje' => $mensaje !== null ? mb_substr($mensaje, 0, 500) : null, 'url' => $url, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo crear la notificación: '.$e->getMessage());
        }
    }
}
