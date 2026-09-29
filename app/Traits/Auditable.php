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
            $ocultos = static::camposOcultosDeAuditoria($model);

            if ($accion === 'creado') {
                $datosDespues = static::redactar($model->getAttributes(), $ocultos);
            } elseif ($accion === 'actualizado') {
                $cambios = $model->getChanges();
                $original = $model->getOriginal();

                $datosAntes = [];
                foreach (array_keys($cambios) as $campo) {
                    $datosAntes[$campo] = $original[$campo] ?? null;
                }

                $datosAntes = static::redactar($datosAntes, $ocultos);
                $datosDespues = static::redactar($cambios, $ocultos);
            } elseif ($accion === 'eliminado') {
                $datosAntes = static::redactar($model->getAttributes(), $ocultos);
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

    /**
     * Campos que nunca deben quedar en texto plano dentro del log de auditoría:
     * credenciales (password, remember_token) siempre, más cualquier campo cuyo
     * nombre termine en _base64/_hash (firmas de firma_ciudadano_base64,
     * firma_expositor_hash, etc.) — contenido biométrico o de integridad pesado
     * que no aporta nada auditable y no debería duplicarse fuera de su tabla
     * original. Un modelo puede sumar campos propios con `protected $auditOculto`.
     */
    protected static function camposOcultosDeAuditoria($model): array
    {
        $porDefecto = ['password', 'remember_token'];
        $propios = property_exists($model, 'auditOculto') ? $model->auditOculto : [];

        $porPatron = array_filter(array_keys($model->getAttributes()), function ($campo) {
            return str_ends_with($campo, '_base64') || str_ends_with($campo, '_hash');
        });

        return array_values(array_unique(array_merge($porDefecto, $propios, $porPatron)));
    }

    protected static function redactar(?array $datos, array $ocultos): ?array
    {
        if ($datos === null) {
            return null;
        }

        foreach ($ocultos as $campo) {
            if (array_key_exists($campo, $datos)) {
                $datos[$campo] = '[oculto]';
            }
        }

        return $datos;
    }
}
