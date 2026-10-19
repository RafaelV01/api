<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionPersona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Personas únicas (un ciudadano = una ficha, vaya a cuantas reuniones vaya) y su historial de participaciones.
 * Solo administrador. Nunca devuelve las firmas.
 */
class PersonaController extends Controller
{
    /** Listado paginado con buscador por nombre o documento. */
    public function index(Request $request)
    {
        $consulta = CaracterizacionPersona::query()
            ->select(['id', 'tipo_documento', 'numero_documento', 'nombre', 'municipio', 'zona', 'genero', 'edad', 'contacto', 'participaciones', 'ultima_participacion_en'])
            ->orderByDesc('ultima_participacion_en')->orderByDesc('id');

        if ($request->filled('q')) {
            $texto = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('q'))).'%';
            $consulta->where(fn ($w) => $w->where('nombre', 'like', $texto)->orWhere('numero_documento', 'like', $texto));
        }
        if ($request->filled('municipio')) {
            $consulta->where('municipio', $request->string('municipio'));
        }
        if ($request->filled('recurrentes')) {
            $consulta->where('participaciones', '>', 1);
        }

        return response()->json($consulta->paginate(min(max($request->integer('per_page', 20), 5), 100)));
    }

    /** Ficha de la persona con cada reunión en la que participó y lo que recibió en ella. */
    public function show(CaracterizacionPersona $persona)
    {
        $participaciones = DB::table('caracterizacion_asistentes as a')
            ->join('caracterizacion_actividades as act', 'act.id', '=', 'a.actividad_id')
            ->leftJoin('caracterizacion_secretarias as sec', 'sec.id', '=', 'act.secretaria_id')
            ->leftJoin('caracterizacion_dependencias as dep', 'dep.id', '=', 'act.dependencia_id')
            ->leftJoin('caracterizacion_seguimiento_asistentes as sa', 'sa.asistente_id', '=', 'a.id')
            ->leftJoin('caracterizacion_seguimientos as sg', 'sg.actividad_id', '=', 'a.actividad_id')
            ->where('a.persona_id', $persona->id)
            ->orderByDesc('act.fecha')->orderByDesc('a.id')
            ->select([
                'a.id as participacion_id', 'act.id as actividad_id', 'act.tema', 'act.fecha', 'act.municipio', 'act.estado_aprobacion',
                'sec.nombre as secretaria', 'dep.nombre as dependencia',
                DB::raw('IF(sa.id IS NOT NULL, sa.item21_categoria, sg.item21_categoria) as categoria'),
                DB::raw('IF(sa.id IS NOT NULL, sa.item21_bien_servicio, sg.item21_bien_servicio) as bien_servicio'),
                DB::raw('IF(sa.id IS NOT NULL, sa.item26_programa, sg.item26_programa) as programa'),
                DB::raw('(sa.id IS NOT NULL) as seguimiento_propio'),
            ])->limit(500)->get();

        return response()->json(['persona' => $persona, 'participaciones' => $participaciones]);
    }
}
