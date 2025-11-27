<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'nombre_completo' => 'required|string|max:160',
            'cargo' => 'required|string|max:120',
            'dependencia' => 'required|string|max:160',
            'email' => 'required|string|email|max:190|unique:usuarios',
            'telefono' => 'required|string|max:30',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'nombre_completo' => $request->nombre_completo,
            'cargo' => $request->cargo,
            'dependencia' => $request->dependencia,
            'email' => $request->email,
            'telefono' => $request->telefono,
            'password' => Hash::make($request->password),
            'rol_id' => 3,
        ]);

        return response()->json(['message' => 'Usuario registrado exitosamente.'], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['Las credenciales proporcionadas son incorrectas.']]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['message' => 'Login exitoso', 'access_token' => $token, 'token_type' => 'Bearer', 'user' => $user]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesion cerrada exitosamente.']);
    }
}