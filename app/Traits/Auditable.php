<?php

namespace App\Traits;

use App\Models\LogActividad;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Registra automáticamente en `logs_actividad` cada creación, actualización y
 * eliminación de los modelos que usan este trait — así ninguna acción del
 * sistema (incluido el administrador) queda fuera del log de auditoría.
 *
 * Uso: `use App\Traits\Auditable;` + `use Auditable;` dentro del modelo.
 * Laravel invoca automáticamente `bootAuditable()` al arrancar el modelo,
 * por la convención `boot{NombreDelTrait}`.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::registrarLogActividad($model, 'creado');
        });

        static::updated(function ($model) {
            // Evita ruido de no-ops (p. ej. un touch() o un save() sin cambios reales).
            if (empty($model->getChanges())) {
                return;
            }

            static::registrarLogActividad($model, 'actualizado');
        });

        static::deleted(function ($model) {
            static::registrarLogActividad($model, 'eliminado');
        });
    }

    /**
     * Escribe una fila en logs_actividad. Nunca debe poder tumbar la operación
     * real (creación/actualización/eliminación) que la originó: cualquier error
     * al registrar el log se atrapa y se reporta con Log::error(), pero jamás
     * se relanza.
     */
    protected static function registrarLogActividad($model, string $accion): void
    {
        // Paranoia: LogActividad no debería usar este trait, pero por si acaso,
        // nunca auditamos el propio modelo de auditoría (evita bucles infinitos).
        if (static::class === LogActividad::class) {
            return;
        }

        try {
            $datosAntes = null;
            $datosDespues = null;

            if ($accion === 'creado') {
                $datosDespues = $model->getAttributes();
            } elseif ($accion === 'actualizado') {
                $cambios = $model->getChanges();
                $original = $model->getOriginal();

                $datosAntes = [];
                foreach (array_keys($cambios) as $campo) {
                    $datosAntes[$campo] = $original[$campo] ?? null;
                }

                $datosDespues = $cambios;
            } elseif ($accion === 'eliminado') {
                $datosAntes = $model->getAttributes();
            }

            LogActividad::create([
                'usuario_id' => Auth::id(),
                'usuario_email' => Auth::user()?->email,
                'accion' => $accion,
                'entidad_tipo' => class_basename(static::class),
                'entidad_id' => $model->getKey(),
                'datos_antes' => $datosAntes,
                'datos_despues' => $datosDespues,
                'ip' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // El log de auditoría jamás debe romper la operación real: se registra
            // el fallo en el log de aplicación y se sigue sin relanzar la excepción.
            Log::error('Auditable: fallo al registrar log de actividad', [
                'entidad_tipo' => class_basename(static::class),
                'accion' => $accion,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
