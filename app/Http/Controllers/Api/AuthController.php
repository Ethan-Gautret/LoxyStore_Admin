<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authentifie un utilisateur et retourne un token Sanctum.
     *
     * Le front (SPA sur un domaine distinct) stocke ce token et l'envoie dans
     * l'en-tête Authorization: Bearer <token> sur chaque requête. On reste donc
     * stateless (pas de cookie de session cross-domain).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            // Message unique : ne pas révéler si l'email existe.
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        // On révoque les anciens tokens de cet appareil (nom fixe) pour éviter
        // l'accumulation de tokens à chaque connexion.
        $user->tokens()->where('name', 'spa')->delete();

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->publicUser($user),
        ]);
    }

    /**
     * Retourne l'utilisateur actuellement authentifié (validation du token).
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user'    => $this->publicUser($request->user()),
        ]);
    }

    /**
     * Révoque le token courant (déconnexion).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    private function publicUser(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ];
    }
}
