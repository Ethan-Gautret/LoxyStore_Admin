<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Models\MarginRule;
use App\Models\TDSCategory;
use App\Models\TDSynexProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarginRuleController extends Controller
{
    /**
     * Règles spécifiques : les catégories mappées ayant une marge personnalisée
     * (category_mappings.margin_override non nul). Cette marge prime sur la marge
     * globale lors du push (voir CategorySyncController). Définie/éditée depuis la
     * page Catégories ; ici on ne fait que l'afficher.
     */
    public function specificRules(): JsonResponse
    {
        $mappings = CategoryMapping::query()
            ->whereNotNull('margin_override')
            ->where('ignored', false)
            ->get();

        $codes = $mappings->pluck('tds_category')->all();

        $names = $codes === [] ? collect()
            : TDSCategory::whereIn('code', $codes)->pluck('name', 'code');

        $counts = $codes === [] ? collect()
            : TDSynexProduct::whereIn('category_tds', $codes)
                ->selectRaw('category_tds, COUNT(*) as c')
                ->groupBy('category_tds')
                ->pluck('c', 'category_tds');

        $rules = $mappings->map(fn (CategoryMapping $m) => [
            'id'             => $m->id,
            'tds_category'   => $m->tds_category,
            'name'           => $names[$m->tds_category] ?? $m->tds_category,
            'margin_value'   => (float) $m->margin_override,
            'active'         => (bool) $m->active,
            'product_count'  => (int) ($counts[$m->tds_category] ?? 0),
            'ps_category_id' => $m->ps_category_id,
        ])->sortBy('name')->values()->all();

        return response()->json([
            'success' => true,
            'rules'   => $rules,
        ]);
    }

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
