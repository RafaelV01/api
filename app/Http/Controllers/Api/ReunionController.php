<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reunion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf; 






class ReunionController extends Controller
{
    
    
    
    
    /**
     * Muestra una lista de las reuniones creadas por el usuario autenticado.
     */
    public function index()
    {
        $user = Auth::user();
        $reuniones = $user->reunionesCreadas()->latest()->get();
        return response()->json($reuniones);
    }

    /**
     * Almacena una nueva reunión en la base de datos.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'tema' => 'required|string|max:200',
            'fecha' => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
            'dependencia_lugar' => 'required|string|max:200',
            'ciudad_municipio' => 'required|string|max:120',
            'tipo_evento' => 'required|in:capacitacion,divulgacion,otro',
            'otro_evento' => 'nullable|string|max:100|required_if:tipo_evento,otro',
            'expositor' => 'required|string|max:160',
            'firma_creador' => 'required|string',
        ]);

        $user = Auth::user();

        $reunion = $user->reunionesCreadas()->create([
            'tema' => $validatedData['tema'],
            'fecha' => $validatedData['fecha'],
            'hora_inicio' => $validatedData['hora_inicio'],
            'hora_fin' => $validatedData['hora_fin'],
            'dependencia_lugar' => $validatedData['dependencia_lugar'],
            'ciudad_municipio' => $validatedData['ciudad_municipio'],
            'tipo_evento' => $validatedData['tipo_evento'],
            'otro_evento' => $validatedData['otro_evento'] ?? null,
            'expositor' => $validatedData['expositor'],
            'codigo' => Str::upper(Str::random(8)),
            'slug_acceso' => Str::slug($validatedData['tema']) . '-' . uniqid(),
            'firma_creador' => $validatedData['firma_creador'],
        ]);

        return response()->json($reunion, 201);
    }

    /**
     * Muestra los detalles de una reunión específica.
     */
    public function show(Reunion $reunion)
    {
        // Verificamos que el usuario autenticado sea el creador de la reunión
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado para ver esta reunión.'], 403);
        }

        // Cargamos la relación con los asistentes, los datos del usuario de cada asistente y el creador
        $reunion->load(['asistentes.usuario', 'creador']);

        return response()->json($reunion);
    }

    /**
     * Genera un reporte PDF de la lista de asistencia.
     */
    public function generarPdf(Reunion $reunion)
    {
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Limpiar cualquier salida previa
        if (ob_get_contents()) {
            ob_end_clean();
        }

        $reunion->load('asistentes.firma');

        $pdf = Pdf::loadView('pdf.asistencia', ['reunion' => $reunion]);

        // Devolver el PDF con encabezados explícitos
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="asistencia-' . $reunion->codigo . '.pdf"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
    }

    /**
     * Finaliza una reunión, impidiendo nuevos registros.
     */
    public function finalizar(Reunion $reunion)
    {
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $reunion->estado = 'cerrada';
        $reunion->save();

        // Recargar la instancia con las relaciones necesarias para el frontend
        $reunion->load('asistentes.usuario');

        return response()->json(['message' => 'Reunión finalizada correctamente.', 'reunion' => $reunion]);
    }
}
