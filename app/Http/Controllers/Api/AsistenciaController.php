<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FirmaDigital;
use App\Models\Reunion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AsistenciaController extends Controller
{
    /**
     * Obtiene la información pública de una reunión por su código de acceso.
     * Esta ruta es pública.
     */
    public function info(string $codigo)
    {
        $reunion = Reunion::where('codigo', $codigo)->firstOrFail();
        return response()->json($reunion);
    }

    /**
     * Registra la asistencia de un usuario autenticado a una reunión.
     * Esta ruta es protegida.
     */
    public function unirse(Request $request, string $codigo)
    {
        $request->validate([
            'firma_base64' => 'required|string',
            'firma_hash' => 'required|string|size:64',
        ]);

        $reunion = Reunion::where('codigo', $codigo)->firstOrFail();

        if ($reunion->estado === 'cerrada') {
            return response()->json(['message' => 'La Reunión Finalizó'], 403);
        }

        // Intentar obtener el usuario autenticado (si existe token)
        $user = Auth::guard('sanctum')->user();

        // Si no hay usuario, verificar si la reunión permite invitados
        if (!$user) {
             if (!$reunion->allow_guests) {
                 return response()->json(['message' => 'Debe iniciar sesión para registrar asistencia.'], 401);
             }

             // Validar datos de invitado
             $request->validate([
                 'nombre_completo' => 'required|string|max:255',
                 'cargo' => 'required|string|max:100',
                 'dependencia' => 'required|string|max:150',
                 'email' => 'required|email|max:255',
                 'telefono' => 'required|string|max:20',
             ]);
        }

        // Usamos una transacción para asegurar que todo se guarde correctamente
        return DB::transaction(function () use ($request, $reunion, $user) {
            // 1. Guardar la firma digital
            $firma = FirmaDigital::create([
                'owner_tipo' => 'asistente',
                'owner_id' => $user ? $user->id : null, 
                'formato' => 'png',
                'data_base64' => $request->firma_base64,
                'hash_integridad' => $request->firma_hash,
            ]);

            // 2. Crear el registro de asistencia
            $asistenteData = [
                'usuario_id' => $user ? $user->id : null,
                'nombre_completo' => $user ? $user->nombre_completo : $request->nombre_completo,
                'cargo' => $user ? $user->cargo : $request->cargo,
                'dependencia' => $user ? $user->dependencia : $request->dependencia,
                'email' => $user ? $user->email : $request->email,
                'telefono' => $user ? $user->telefono : $request->telefono,
                'firma_id' => $firma->id,
                'creado_via' => 'enlace', 
            ];


            $asistente = $reunion->asistentes()->create($asistenteData);

            if (!$user) {
                // Actualizar el owner_id de la firma con el id del asistente recién creado
                $firma->owner_id = $asistente->id;
                $firma->save();
            }


            return response()->json([
                'message' => 'Asistencia registrada exitosamente.'
            ], 201);
        });
    }
}