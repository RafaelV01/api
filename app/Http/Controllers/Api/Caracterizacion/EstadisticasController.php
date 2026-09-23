<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EstadisticasController extends Controller
{
    /**
     * Cruce municipio × {zona, género, étnico, sector-organización, enfoque-diferencial,
     * grupos-etáreos} + total, replicando la forma de las hojas ACTIVIDAD/PROYECTO del
     * Excel fuente — calculado en vivo por SQL, no mantenido a mano.
     *
     * Filtros: secretaria_id, dependencia_id, usuario_id (creador), fecha_desde, fecha_hasta, municipio.
     */
    public function index(Request $request)
    {
        $aplicarFiltros = function ($query) use ($request) {
            $query->join('caracterizacion_actividades', 'caracterizacion_actividades.id', '=', 'caracterizacion_asistentes.actividad_id');

            if ($request->filled('secretaria_id')) {
                $query->where('caracterizacion_actividades.secretaria_id', $request->integer('secretaria_id'));
            }
            if ($request->filled('dependencia_id')) {
                $query->where('caracterizacion_actividades.dependencia_id', $request->integer('dependencia_id'));
            }
            if ($request->filled('usuario_id')) {
                $query->where('caracterizacion_actividades.creador_id', $request->integer('usuario_id'));
            }
            if ($request->filled('fecha_desde')) {
                $query->whereDate('caracterizacion_actividades.fecha', '>=', $request->date('fecha_desde'));
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('caracterizacion_actividades.fecha', '<=', $request->date('fecha_hasta'));
            }
            if ($request->filled('municipio')) {
                $query->where('caracterizacion_asistentes.item5_municipio', $request->string('municipio'));
            }

            return $query;
        };

        $principal = $aplicarFiltros(DB::table('caracterizacion_asistentes'))
            ->select('caracterizacion_asistentes.item5_municipio as municipio')
            ->selectRaw("SUM(item6_zona = 'urbana') as zona_urbana")
            ->selectRaw("SUM(item6_zona = 'rural') as zona_rural")
            ->selectRaw("SUM(item9_genero = 'Mujer') as genero_mujer")
            ->selectRaw("SUM(item9_genero = 'Hombre') as genero_hombre")
            ->selectRaw("SUM(item9_genero = 'No Binario') as genero_no_binario")
            ->selectRaw("SUM(item9_genero = 'Transgénero') as genero_transgenero")
            ->selectRaw("SUM(item9_genero = 'LGBTIQ+ OSIGD') as genero_lgbtiq")
            ->selectRaw("SUM(item10_etnico = 'Indígenas') as etnico_indigenas")
            ->selectRaw("SUM(item10_etnico = 'Comun. Negras - Afro- Raizales - Palenqueras') as etnico_negras_afro")
            ->selectRaw("SUM(item10_etnico = 'Pueblo Rom o Gitano') as etnico_rom")
            ->selectRaw("SUM(item13_clasificacion_organizacion = 'Con Ánimo de Lucro') as organizacion_con_animo_lucro")
            ->selectRaw("SUM(item13_clasificacion_organizacion = 'Sin Ánimo de Lucro') as organizacion_sin_animo_lucro")
            ->selectRaw("SUM(item25_grupo_etareo = 'Primera infancia') as grupo_primera_infancia")
            ->selectRaw("SUM(item25_grupo_etareo = 'Infancia') as grupo_infancia")
            ->selectRaw("SUM(item25_grupo_etareo = 'Adolescencia') as grupo_adolescencia")
            ->selectRaw("SUM(item25_grupo_etareo = 'Jóvenes') as grupo_jovenes")
            ->selectRaw("SUM(item25_grupo_etareo = 'Adultos') as grupo_adultos")
            ->selectRaw("SUM(item25_grupo_etareo = 'Mayores') as grupo_mayores")
            ->selectRaw('COUNT(*) as total_poblacion_atendida')
            ->groupBy('caracterizacion_asistentes.item5_municipio')
            ->get()
            ->keyBy('municipio');

        $enfoques = $aplicarFiltros(
                DB::table('caracterizacion_asistente_enfoques')
                    ->join('caracterizacion_asistentes', 'caracterizacion_asistentes.id', '=', 'caracterizacion_asistente_enfoques.asistente_id')
            )
            ->select('caracterizacion_asistentes.item5_municipio as municipio', 'caracterizacion_asistente_enfoques.valor')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('caracterizacion_asistentes.item5_municipio', 'caracterizacion_asistente_enfoques.valor')
            ->get()
            ->groupBy('municipio');

        $resultado = $principal->map(function ($fila) use ($enfoques) {
            $porMunicipio = $enfoques->get($fila->municipio, collect());

            $enfoqueDe = fn (string $valor) => (int) optional($porMunicipio->firstWhere('valor', $valor))->total;

            return [
                'municipio' => $fila->municipio,
                'zona' => ['urbana' => (int) $fila->zona_urbana, 'rural' => (int) $fila->zona_rural],
                'genero' => [
                    'mujer' => (int) $fila->genero_mujer,
                    'hombre' => (int) $fila->genero_hombre,
                    'no_binario' => (int) $fila->genero_no_binario,
                    'transgenero' => (int) $fila->genero_transgenero,
                    'lgbtiq_osigd' => (int) $fila->genero_lgbtiq,
                ],
                'etnico' => [
                    'indigenas' => (int) $fila->etnico_indigenas,
                    'negras_afro_raizales_palenqueras' => (int) $fila->etnico_negras_afro,
                    'pueblo_rom' => (int) $fila->etnico_rom,
                ],
                'sector_organizacion' => [
                    'con_animo_lucro' => (int) $fila->organizacion_con_animo_lucro,
                    'sin_animo_lucro' => (int) $fila->organizacion_sin_animo_lucro,
                ],
                'enfoque_diferencial' => [
                    'discapacidad' => $enfoqueDe('Discapacidad'),
                    'cabeza_de_hogar' => $enfoqueDe('Cabeza de Hogar'),
                    'victimas_de_conflicto' => $enfoqueDe('Víctimas de Conflicto'),
                    'habitante_de_la_calle' => $enfoqueDe('Habitante de la calle'),
                ],
                'grupos_etareos' => [
                    'primera_infancia' => (int) $fila->grupo_primera_infancia,
                    'infancia' => (int) $fila->grupo_infancia,
                    'adolescencia' => (int) $fila->grupo_adolescencia,
                    'jovenes' => (int) $fila->grupo_jovenes,
                    'adultos' => (int) $fila->grupo_adultos,
                    'mayores' => (int) $fila->grupo_mayores,
                ],
                'total_poblacion_atendida' => (int) $fila->total_poblacion_atendida,
            ];
        })->values();

        return response()->json([
            'por_municipio' => $resultado,
            'total_general' => $resultado->sum('total_poblacion_atendida'),
        ]);
    }
}
