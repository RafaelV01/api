<?php

namespace App\Http\Controllers\Api\Caracterizacion;

use App\Http\Controllers\Controller;
use App\Models\CaracterizacionDependencia;
use App\Models\CaracterizacionPerfil;
use App\Models\CaracterizacionSecretaria;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgController extends Controller
{
    /**
     * Perfil de caracterización del usuario autenticado (rol, secretaría, dependencia).
     * Lo usa el frontend (Header.jsx) para decidir qué navegación mostrar.
     */
    public function miPerfil(Request $request)
    {
        $perfil = $request->user()->caracterizacionPerfil()->with(['secretaria', 'dependencia'])->first();

        if (!$perfil) {
            return response()->json(['rol' => null]);
        }

        return response()->json($perfil);
    }

    // ─── Secretarías ────────────────────────────────────────────────────────
    public function secretarias()
    {
        return response()->json(CaracterizacionSecretaria::with('dependencias')->orderBy('nombre')->get());
    }

    public function crearSecretaria(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:caracterizacion_secretarias,nombre',
        ]);

        return response()->json(CaracterizacionSecretaria::create($validated), 201);
    }

    public function actualizarSecretaria(Request $request, CaracterizacionSecretaria $secretaria)
    {
        $validated = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255', $this->noVacio(), Rule::unique('caracterizacion_secretarias', 'nombre')->ignore($secretaria->id)],
            'activo' => 'sometimes|boolean',
        ]);

        $secretaria->update($validated);

        // Al desactivar la secretaría, sus dependencias se desactivan con ella —
        // el diálogo de confirmación del frontend lo promete explícitamente, y antes
        // no ocurría de verdad (seguían activas y asignables). Se recorre y guarda
        // cada dependencia como modelo, no un update() en bloque, para que Auditable
        // registre cada una individualmente.
        // La regla 'boolean' de Laravel solo verifica que el valor esté en
        // [true,false,0,1,'0','1'] — no lo castea. Sin (bool) aquí, una petición
        // que llegue como form-urlencoded/multipart con "activo=0" (string "0")
        // no dispararía la cascada porque "0" === false es false en PHP.
        if (array_key_exists('activo', $validated) && (bool) $validated['activo'] === false) {
            foreach ($secretaria->dependencias as $dependencia) {
                if ($dependencia->activo) {
                    $dependencia->update(['activo' => false]);
                }
            }
        }

        return response()->json($secretaria->fresh('dependencias'));
    }

    /**
     * Regla de validación reutilizable: rechaza vacío o solo espacios. `required`/
     * `filled` no bastan porque PHP no considera vacía una cadena de solo espacios.
     */
    private function noVacio(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (trim((string) $value) === '') {
                $fail('El campo no puede quedar vacío.');
            }
        };
    }

    // ─── Dependencias ───────────────────────────────────────────────────────
    public function dependencias(Request $request)
    {
        $query = CaracterizacionDependencia::with('secretaria')->orderBy('nombre');

        if ($request->filled('secretaria_id')) {
            $query->where('secretaria_id', $request->integer('secretaria_id'));
        }

        return response()->json($query->get());
    }

    public function crearDependencia(Request $request)
    {
        $validated = $request->validate([
            // Solo se puede crear bajo una secretaría activa; de lo contrario quedaba
            // como una forma silenciosa de "reactivar" secretarías desactivadas.
            'secretaria_id' => ['required', Rule::exists('caracterizacion_secretarias', 'id')->where('activo', true)],
            'nombre' => [
                'required', 'string', 'max:255', $this->noVacio(),
                Rule::unique('caracterizacion_dependencias', 'nombre')->where('secretaria_id', $request->input('secretaria_id')),
            ],
        ]);

        return response()->json(CaracterizacionDependencia::create($validated), 201);
    }

    public function actualizarDependencia(Request $request, CaracterizacionDependencia $dependencia)
    {
        $validated = $request->validate([
            'nombre' => [
                'sometimes', 'string', 'max:255', $this->noVacio(),
                Rule::unique('caracterizacion_dependencias', 'nombre')
                    ->where('secretaria_id', $dependencia->secretaria_id)
                    ->ignore($dependencia->id),
            ],
            'activo' => 'sometimes|boolean',
        ]);

        $dependencia->update($validated);

        return response()->json($dependencia);
    }

    /**
     * Listado mínimo de usuarios (id, nombre, email) para el selector de "invitar
     * colaborador" de una actividad. A propósito NO exige es.admin (a diferencia de
     * GET /api/usuarios): basta con tener un perfil de caracterización activo, para
     * que un contratista normal también pueda invitar a alguien sin ser admin del
     * sistema completo.
     *
     * Devuelve TODOS los usuarios (no solo quienes ya tienen perfil de
     * caracterización): autorizarAcceso() en ActividadController no exige perfil
     * para ser colaborador aceptado — cualquier usuario del sistema es un invitado
     * válido — así que acotar este listado a "solo quienes ya participan" dejaba el
     * selector vacío en la práctica (la inmensa mayoría de usuarios no tiene perfil
     * asignado) y rompía la función real. Se mantiene acotado a id/nombre/email
     * (no el registro completo) como mitigación razonable de exposición de datos.
     */
    public function usuariosDisponibles(Request $request)
    {
        abort_unless(
            $request->user()->caracterizacionPerfil()->where('activo', true)->exists(),
            403,
            'No autorizado.'
        );

        return response()->json(
            User::select('id', 'nombre_completo', 'email')
                ->orderBy('nombre_completo')
                ->get()
        );
    }

    // ─── Perfiles (asignación de rol dentro de Caracterización) ────────────
    public function perfiles()
    {
        return response()->json(
            CaracterizacionPerfil::with(['usuario:id,nombre_completo,email', 'secretaria', 'dependencia'])->get()
        );
    }

    public function asignarPerfil(Request $request)
    {
        $validated = $request->validate([
            'usuario_id' => 'required|exists:usuarios,id|unique:caracterizacion_perfiles,usuario_id',
            'rol' => ['required', Rule::in(CaracterizacionPerfil::ROLES)],
            'secretaria_id' => 'nullable|exists:caracterizacion_secretarias,id|required_if:rol,secretaria',
            'dependencia_id' => 'nullable|exists:caracterizacion_dependencias,id|required_if:rol,contratista',
        ]);

        return response()->json(CaracterizacionPerfil::create($validated), 201);
    }

    public function actualizarPerfil(Request $request, CaracterizacionPerfil $perfil)
    {
        $validated = $request->validate([
            'rol' => ['sometimes', Rule::in(CaracterizacionPerfil::ROLES)],
            // Mismas reglas required_if que asignarPerfil() — antes solo se exigían
            // al crear, así que editar un perfil a rol=secretaria/contratista podía
            // dejarlo sin secretaria_id/dependencia_id, bloqueado silenciosamente en
            // scopeVisiblePara/store() sin ningún aviso al admin.
            'secretaria_id' => 'nullable|exists:caracterizacion_secretarias,id|required_if:rol,secretaria',
            'dependencia_id' => 'nullable|exists:caracterizacion_dependencias,id|required_if:rol,contratista',
            'activo' => 'sometimes|boolean',
        ]);

        $perfil->update($validated);

        return response()->json($perfil);
    }
}
