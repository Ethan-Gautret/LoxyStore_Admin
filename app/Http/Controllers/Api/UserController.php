<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD des utilisateurs autorisés à accéder au back-office.
 *
 * Tous les utilisateurs connectés sont au même niveau (pas de rôles) : chacun
 * peut créer, modifier et supprimer des comptes. La seule garde est qu'on ne
 * peut pas supprimer le dernier compte (sinon plus personne ne peut se connecter).
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at']);

        return response()->json([
            'success' => true,
            'users'   => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $validated['password'], // cast 'hashed' sur le modèle
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé.',
            'user'    => $user->only(['id', 'name', 'email', 'created_at']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // Mot de passe optionnel : vide = inchangé.
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }
        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur mis à jour.',
            'user'    => $user->only(['id', 'name', 'email', 'created_at']),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (User::query()->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer le dernier utilisateur.',
            ], 422);
        }

        // Empêche de se supprimer soi-même pendant la session courante.
        if ($request->user() && $request->user()->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé.',
        ]);
    }
}
