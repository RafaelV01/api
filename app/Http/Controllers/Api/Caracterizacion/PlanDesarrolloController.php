<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Services\ImportadorPlanDesarrollo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plan de Desarrollo Departamental (sector → programa → meta producto).
 *  - index: árbol para los desplegables en cascada de la Parte 2 (cualquier usuario autenticado).
 *  - admin*: gestión desde la pantalla de administración (solo administrador).
 */
class PlanDesarrolloController extends Controller
{
    public function index()
    {
        // Se ofrece la vigencia más reciente que tenga metas activas (así, al cargar el próximo plan de gobierno, pasa a ser el de los desplegables).
        $vigencia = DB::table('caracterizacion_plan_desarrollo')->where('activo', true)->max('vigencia');
        $filas = DB::table('caracterizacion_plan_desarrollo')
            ->where('activo', true)->where('vigencia', $vigencia)
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

    /** Listado paginado con buscador y filtros (administración). */
    public function admin(Request $request)
    {
        $consulta = DB::table('caracterizacion_plan_desarrollo')->orderBy('orden')->orderBy('id');

        if ($request->filled('q')) {
            $texto = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('q'))).'%';
            $consulta->where(fn ($w) => $w->where('meta_producto', 'like', $texto)->orWhere('programa', 'like', $texto)->orWhere('sector', 'like', $texto));
        }
        if ($request->filled('sector')) {
            $consulta->where('sector', $request->string('sector'));
        }
        if ($request->filled('activo')) {
            $consulta->where('activo', $request->boolean('activo'));
        }
        if ($request->filled('vigencia')) {
            $consulta->where('vigencia', $request->string('vigencia'));
        }

        return response()->json([
            'filas' => $consulta->paginate(min(max($request->integer('per_page', 20), 5), 100)),
            'sectores' => DB::table('caracterizacion_plan_desarrollo')->distinct()->orderBy('sector')->pluck('sector'),
            'vigencias' => DB::table('caracterizacion_plan_desarrollo')->distinct()->orderBy('vigencia')->pluck('vigencia'),
        ]);
    }

    private function reglas(): array
    {
        return [
            'sector' => 'required|string|max:150',
            'programa' => 'required|string|max:255',
            'meta_producto' => 'required|string|max:800',
            'orden' => 'nullable|integer|min:0|max:100000',
            'vigencia' => 'nullable|string|max:20',
            'activo' => 'nullable|boolean',
        ];
    }

    public function guardar(Request $request)
    {
        $v = $request->validate($this->reglas());
        $id = DB::table('caracterizacion_plan_desarrollo')->insertGetId([
            'sector' => trim($v['sector']), 'programa' => trim($v['programa']), 'meta_producto' => trim($v['meta_producto']),
            'orden' => $v['orden'] ?? 0, 'vigencia' => $v['vigencia'] ?? '2024-2027', 'activo' => $v['activo'] ?? true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(DB::table('caracterizacion_plan_desarrollo')->find($id), 201);
    }

    public function actualizar(Request $request, int $id)
    {
        abort_unless(DB::table('caracterizacion_plan_desarrollo')->where('id', $id)->exists(), 404);
        $v = $request->validate(array_map(fn ($r) => str_replace('required|', 'sometimes|required|', $r), $this->reglas()));
        $cambios = array_intersect_key($v, array_flip(['sector', 'programa', 'meta_producto', 'orden', 'vigencia', 'activo']));
        foreach (['sector', 'programa', 'meta_producto'] as $campo) {
            if (isset($cambios[$campo])) {
                $cambios[$campo] = trim($cambios[$campo]);
            }
        }
        DB::table('caracterizacion_plan_desarrollo')->where('id', $id)->update($cambios + ['updated_at' => now()]);

        return response()->json(DB::table('caracterizacion_plan_desarrollo')->find($id));
    }

    /** Carga masiva desde un archivo CSV o JSON (columnas: sector, programa, meta_producto, orden, vigencia). */
    public function importar(Request $request, ImportadorPlanDesarrollo $importador)
    {
        $request->validate([
            'archivo' => 'required|file|max:5120|mimes:csv,txt,json',
            'reemplazar' => 'nullable|boolean',
            'vigencia' => 'nullable|string|max:20',
        ]);

        $contenido = (string) file_get_contents($request->file('archivo')->getRealPath());
        $filas = str_starts_with(ltrim($contenido), '[') ? json_decode($contenido, true) : $importador->leerCsv($contenido);
        if (! is_array($filas) || ! $filas) {
            return response()->json(['message' => 'El archivo no tiene filas válidas. Debe traer los encabezados sector, programa y meta_producto.'], 422);
        }

        return response()->json($importador->importar($filas, $request->boolean('reemplazar'), (string) $request->input('vigencia', '2024-2027')));
    }
}
