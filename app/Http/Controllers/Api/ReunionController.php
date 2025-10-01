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
            'dependencia_lugar' => 'required|string|max:200',
            'ciudad_municipio' => 'required|string|max:120',
            'tipo_evento' => 'required|in:capacitacion,divulgacion,otro',
            'expositor' => 'required|string|max:160',
        ]);

        $user = Auth::user();

        $reunion = $user->reunionesCreadas()->create([
            'tema' => $validatedData['tema'],
            'fecha' => $validatedData['fecha'],
            'hora_inicio' => $validatedData['hora_inicio'],
            'dependencia_lugar' => $validatedData['dependencia_lugar'],
            'ciudad_municipio' => $validatedData['ciudad_municipio'],
            'tipo_evento' => $validatedData['tipo_evento'],
            'expositor' => $validatedData['expositor'],
            'codigo' => Str::upper(Str::random(8)),
            'slug_acceso' => Str::slug($validatedData['tema']) . '-' . uniqid(),
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

        // Cargamos la relación con los asistentes y los datos del usuario de cada asistente
        $reunion->load('asistentes.usuario');

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

        // Cargamos todas las relaciones necesarias para el PDF
        $reunion->load('asistentes.firma');

        // Creamos el PDF pasando la vista y los datos
        $pdf = Pdf::loadView('pdf.asistencia', ['reunion' => $reunion]);

        // Ponemos el PDF en modo horizontal si es necesario
        // $pdf->setPaper('a4', 'landscape');

        // Devolvemos el PDF para que el navegador lo descargue
        return $pdf->download('asistencia-' . $reunion->codigo . '.pdf');
    }
}
