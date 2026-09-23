<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionActividad;
use App\Models\CaracterizacionAsistente;
use App\Models\CaracterizacionAsistenteEnfoque;
use App\Models\CaracterizacionAsistenteProblematica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicController extends Controller
{
    /**
     * Información pública de una actividad por su slug de acceso. Ruta pública,
     * igual que AsistenciaController::info para el sistema de Reuniones.
     */
    public function info(string $slug)
    {
        $actividad = CaracterizacionActividad::where('slug_acceso', $slug)->firstOrFail();

        return response()->json([
            'tema' => $actividad->tema,
            'fecha' => $actividad->fecha,
            'hora' => $actividad->hora,
            'quien_dirige_expone' => $actividad->quien_dirige_expone,
            'municipio' => $actividad->municipio,
            'departamento' => $actividad->departamento,
            'tipo_evento' => $actividad->tipo_evento,
            'estado' => $actividad->estado,
        ]);
    }

    /**
     * Registra la Parte 1 (ítems 1–19) diligenciada por el propio ciudadano. Ruta pública,
     * sin autenticación — igual que AsistenciaController::unirse para invitados.
     */
    public function registrarCiudadano(Request $request, string $slug)
    {
        $actividad = CaracterizacionActividad::where('slug_acceso', $slug)->firstOrFail();

        if ($actividad->estado === 'cerrada') {
            return response()->json(['message' => 'Esta actividad ya finalizó.'], 403);
        }

        $opcion = fn (string $categoria) => Rule::exists('caracterizacion_opciones', 'valor')
            ->where('categoria', $categoria)
            ->where('activo', true);

        $validated = $request->validate([
            'item1_nombre' => 'required|string|max:255',
            'item2_tipo_documento' => ['required', 'string', $opcion('tipo_documento')],
            'item3_numero_documento' => 'required|string|max:30',
            'item4_cargo_barrio_vereda' => 'required|string|max:255',
            'item5_municipio' => ['required', 'string', $opcion('municipio')],
            'item6_zona' => 'required|in:urbana,rural',
            'item7_ubicacion_tipo' => ['required', 'string', $opcion('ubicacion_tipo')],
            'item7_ubicacion_detalle' => 'nullable|string|max:255',
            'item8_contacto_tipo' => ['required', 'string', $opcion('contacto_tipo')],
            'item8_contacto_valor' => 'required|string|max:30',
            'item9_genero' => ['required', 'string', $opcion('genero')],
            'item10_etnico' => ['nullable', 'string', $opcion('etnico')],
            'item11_problematicas' => 'nullable|array',
            'item11_problematicas.*' => ['string', $opcion('problematica')],
            'item12_sector_organizacion' => 'nullable|string|max:150',
            'item13_clasificacion_organizacion' => ['nullable', 'string', $opcion('clasificacion_organizacion')],
            'item14_enfoques' => 'nullable|array',
            'item14_enfoques.*' => ['string', $opcion('enfoque_diferencial')],
            'item15_edad' => 'required|integer|min:0|max:120',
            'item16_tamano_grupo_familiar' => ['required', 'integer', $opcion('tamano_familia')],
            'item17_canal_comunicacion' => ['required', 'string', $opcion('canal_comunicacion')],
            'item18_idioma_lengua_dialecto' => ['required', 'string', $opcion('idioma_lengua_dialecto')],
            'firma_base64' => 'required|string',
            'firma_hash' => 'required|string|size:64',
        ]);

        $asistente = DB::transaction(function () use ($validated, $actividad, $request) {
            $asistente = $actividad->asistentes()->create([
                'item1_nombre' => $validated['item1_nombre'],
                'item2_tipo_documento' => $validated['item2_tipo_documento'],
                'item3_numero_documento' => $validated['item3_numero_documento'],
                'item4_cargo_barrio_vereda' => $validated['item4_cargo_barrio_vereda'],
                'item5_municipio' => $validated['item5_municipio'],
                'item6_zona' => $validated['item6_zona'],
                'item7_ubicacion_tipo' => $validated['item7_ubicacion_tipo'],
                'item7_ubicacion_detalle' => $validated['item7_ubicacion_detalle'] ?? null,
                'item8_contacto_tipo' => $validated['item8_contacto_tipo'],
                'item8_contacto_valor' => $validated['item8_contacto_valor'],
                'item9_genero' => $validated['item9_genero'],
                'item10_etnico' => $validated['item10_etnico'] ?? null,
                'item12_sector_organizacion' => $validated['item12_sector_organizacion'] ?? null,
                'item13_clasificacion_organizacion' => $validated['item13_clasificacion_organizacion'] ?? null,
                'item15_edad' => $validated['item15_edad'],
                'item16_tamano_grupo_familiar' => $validated['item16_tamano_grupo_familiar'],
                'item17_canal_comunicacion' => $validated['item17_canal_comunicacion'],
                'item18_idioma_lengua_dialecto' => $validated['item18_idioma_lengua_dialecto'],
                'firma_ciudadano_base64' => $validated['firma_base64'],
                'firma_ciudadano_hash' => $validated['firma_hash'],
                'ip_origen' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            foreach ($validated['item11_problematicas'] ?? [] as $valor) {
                CaracterizacionAsistenteProblematica::create(['asistente_id' => $asistente->id, 'valor' => $valor]);
            }

            foreach ($validated['item14_enfoques'] ?? [] as $valor) {
                CaracterizacionAsistenteEnfoque::create(['asistente_id' => $asistente->id, 'valor' => $valor]);
            }

            return $asistente;
        });

        return response()->json([
            'message' => 'Registro guardado exitosamente.',
            'asistente_id' => $asistente->id,
        ], 201);
    }
}
