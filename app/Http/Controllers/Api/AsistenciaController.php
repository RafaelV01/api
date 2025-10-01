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
        $user = Auth::user();

        // Usamos una transacción para asegurar que todo se guarde correctamente
        return DB::transaction(function () use ($request, $reunion, $user) {
            // 1. Guardar la firma digital
            $firma = FirmaDigital::create([
                'owner_tipo' => 'asistente',
                'owner_id' => $user->id, // Temporal, se puede asociar al 'asistente_id' después
                'formato' => 'png',
                'data_base64' => $request->firma_base64,
                'hash_integridad' => $request->firma_hash,
            ]);

            // 2. Crear el registro de asistencia
            $asistente = $reunion->asistentes()->create([
                'usuario_id' => $user->id,
                'nombre_completo' => $user->nombre_completo,
                'cargo' => $user->cargo,
                'dependencia' => $user->dependencia,
                'email' => $user->email,
                'telefono' => $user->telefono,
                'firma_id' => $firma->id,
                'creado_via' => 'enlace', // O 'qr', 'codigo', etc.
            ]);

            return response()->json([
                'message' => 'Asistencia registrada exitosamente.'
            ], 201);
        });
    }
}