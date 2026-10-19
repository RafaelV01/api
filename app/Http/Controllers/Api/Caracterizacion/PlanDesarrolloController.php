<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Plan de Desarrollo Departamental (sector → programa → meta producto) en forma de árbol
 * para los desplegables en cascada de la Parte 2. Solo lectura; los datos se cargan con el
 * comando `caracterizacion:importar-plan`.
 */
class PlanDesarrolloController extends Controller
{
    public function index()
    {
        $filas = DB::table('caracterizacion_plan_desarrollo')
            ->where('activo', true)
            ->orderBy('sector')->orderBy('programa')->orderBy('orden')->orderBy('meta_producto')
            ->get(['sector', 'programa', 'meta_producto']);

        $arbol = $filas->groupBy('sector')->map(fn ($porSector, $sector) => [
            'sector' => $sector,
            'programas' => $porSector->groupBy('programa')->map(fn ($porPrograma, $programa) => [
                'programa' => $programa,
                'metas' => $porPrograma->pluck('meta_producto')->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        return response()->json($arbol);
    }
}
