<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarginRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarginRuleController extends Controller
{
    /**
     * Retourne la règle globale (marge % appliquée par défaut à tous les produits
     * qui n'ont pas de marge propre au niveau catégorie).
     */
    public function showGlobal(): JsonResponse
    {
        $rule = MarginRule::query()
            ->where('scope', 'global')
            ->where('active', true)
            ->orderBy('priority')
            ->first();

        return response()->json([
            'success' => true,
            'global'  => [
                'margin_value' => $rule ? (float) $rule->margin_value : MarginRule::DEFAULT_GLOBAL_MARGIN,
                'margin_type'  => $rule->margin_type ?? 'percent',
            ],
        ]);
    }

    /**
     * Enregistre la marge globale (%). Le prix vendu HT = prix achat × (1 + marge%),
     * sans TVA (les prix restent HT).
     */
    public function updateGlobal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'margin_value' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $rule = MarginRule::updateOrCreate(
            ['scope' => 'global'],
            [
                'scope_id'    => null,
                'scope_label' => 'Règle globale',
                'margin_type' => 'percent',
                'margin_value' => $validated['margin_value'],
                'priority'    => 1000, // le global a la priorité la plus basse
                'active'      => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Marge globale enregistrée.',
            'global'  => [
                'margin_value' => (float) $rule->margin_value,
                'margin_type'  => $rule->margin_type,
            ],
        ]);
    }
}
