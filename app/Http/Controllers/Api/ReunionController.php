<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reunion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class ReunionController extends Controller
{
    /**
     * Lista reuniones del usuario — solo campos necesarios, sin firma_creador (base64 pesado).
     */
    public function index()
    {
        $reuniones = Auth::user()
            ->reunionesCreadas()
            ->select('id', 'tema', 'fecha', 'hora_inicio', 'dependencia_lugar', 'estado', 'codigo', 'created_at')
            ->latest()
            ->get();

        return response()->json($reuniones);
    }

    /**
     * Crea una nueva reunión.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'tema'              => 'required|string|max:255',
            'fecha'             => 'required|date',
            'hora_inicio'       => 'required|date_format:H:i',
            'hora_fin'          => 'required|date_format:H:i|after:hora_inicio',
            'dependencia_lugar' => 'required|string|max:200',
            'ciudad_municipio'  => 'required|string|max:120',
            'tipo_evento'       => 'required|in:capacitacion,divulgacion,otro',
            'otro_evento'       => 'nullable|string|max:233|required_if:tipo_evento,otro',
            'expositor'         => 'required|string|max:160',
            'firma_creador'     => 'required|string',
            'allow_guests'      => 'boolean',
        ]);

        $reunion = Auth::user()->reunionesCreadas()->create([
            'tema'              => $validatedData['tema'],
            'fecha'             => $validatedData['fecha'],
            'hora_inicio'       => $validatedData['hora_inicio'],
            'hora_fin'          => $validatedData['hora_fin'],
            'dependencia_lugar' => $validatedData['dependencia_lugar'],
            'ciudad_municipio'  => $validatedData['ciudad_municipio'],
            'tipo_evento'       => $validatedData['tipo_evento'],
            'otro_evento'       => $validatedData['otro_evento'] ?? null,
            'expositor'         => $validatedData['expositor'],
            'codigo'            => Str::upper(Str::random(8)),
            'slug_acceso'       => Str::slug($validatedData['tema']) . '-' . uniqid(),
            'firma_creador'     => $validatedData['firma_creador'],
            'allow_guests'      => $request->boolean('allow_guests'),
        ]);

        return response()->json($reunion, 201);
    }

    /**
     * Detalle de una reunión — eager loading optimizado, sin columnas innecesarias.
     */
    public function show(Reunion $reunion)
    {
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado para ver esta reunión.'], 403);
        }

        // Cargamos solo los campos que el frontend necesita mostrar
        $reunion->load([
            'asistentes' => fn($q) => $q->select(
                'id', 'reunion_id', 'usuario_id',
                'nombre_completo', 'cargo', 'dependencia',
                'email', 'telefono', 'creado_via'
            ),
            'asistentes.usuario' => fn($q) => $q->select('id', 'nombre_completo', 'cargo', 'dependencia'),
            'creador'            => fn($q) => $q->select('id', 'nombre_completo'),
        ]);

        return response()->json($reunion);
    }

    /**
     * Genera el PDF — con caché por reunión, se invalida al finalizar o al unirse alguien.
     */
    public function generarPdf(Request $request, Reunion $reunion)
    {
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Aumentar tiempo límite para generación de PDF con firmas (imágenes base64 pesadas)
        set_time_limit(120);

        // Limpiar todos los output buffers activos
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $cacheKey  = "pdf_reunion_{$reunion->id}_v{$reunion->updated_at->timestamp}";
        $cachePath = storage_path("app/pdf_cache/{$cacheKey}.pdf");

        $pdfContent = null;

        // Validar caché: solo usar el archivo si existe Y es un PDF válido
        if (file_exists($cachePath)) {
            $cached = file_get_contents($cachePath);
            if ($cached && str_starts_with($cached, '%PDF')) {
                $pdfContent = $cached;
            } else {
                // Archivo corrupto — eliminarlo y regenerar
                @unlink($cachePath);
            }
        }

        if ($pdfContent === null) {
            $reunion->load([
                'asistentes'       => fn($q) => $q->select('id', 'reunion_id', 'nombre_completo', 'cargo', 'dependencia', 'email', 'telefono', 'firma_id'),
                'asistentes.firma' => fn($q) => $q->select('id', 'data_base64'),
            ]);

            $pdfContent = Pdf::loadView('pdf.asistencia', ['reunion' => $reunion])->output();

            // Guardar solo si la generación fue exitosa
            if ($pdfContent && str_starts_with($pdfContent, '%PDF')) {
                @mkdir(dirname($cachePath), 0755, true);
                // Escritura atómica: escribir en .tmp y luego renombrar
                $tmpPath = $cachePath . '.tmp';
                if (file_put_contents($tmpPath, $pdfContent) !== false) {
                    rename($tmpPath, $cachePath);
                }
            }
        }

        // Informar al frontend si puede cachear la respuesta (reunión cerrada = estático)
        $cacheControl = $reunion->estado === 'cerrada'
            ? 'public, max-age=3600'   // 1 hora si está cerrada (no cambia más)
            : 'no-store';              // sin caché si está activa

        $disposition = $request->query('modo') === 'ver'
            ? 'inline'
            : 'attachment';

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"asistencia-{$reunion->codigo}.pdf\"",
            'Cache-Control'       => $cacheControl,
        ]);
    }

    /**
     * Finaliza la reunión e invalida el caché del PDF.
     */
    public function finalizar(Reunion $reunion)
    {
        if (Auth::id() !== $reunion->creador_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Borrar PDF cacheado antes de cambiar updated_at
        $oldKey  = "pdf_reunion_{$reunion->id}_v{$reunion->updated_at->timestamp}";
        @unlink(storage_path("app/pdf_cache/{$oldKey}.pdf"));

        $reunion->estado = 'cerrada';
        $reunion->save();

        $reunion->load([
            'asistentes' => fn($q) => $q->select('id', 'reunion_id', 'usuario_id', 'nombre_completo', 'cargo', 'dependencia', 'email', 'telefono', 'creado_via'),
            'asistentes.usuario' => fn($q) => $q->select('id', 'nombre_completo', 'cargo'),
        ]);

        return response()->json([
            'message' => 'Reunión finalizada correctamente.',
            'reunion' => $reunion,
        ]);
    }
}
