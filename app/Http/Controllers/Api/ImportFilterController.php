<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Models\ImportFilter;
use App\Models\TDSCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportFilterController extends Controller
{
    public const ATTRIBUTES = ['ean', 'weight', 'description', 'image'];
    public const STOCK_BEHAVIOURS = ['disable', 'keep', 'delete'];
    public const PRICE_ROUNDINGS = ['none', 'psychological', 'round'];

    /** Réglages globaux des filtres d'import. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'filters' => $this->shape(ImportFilter::current()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_stock'             => ['required', 'integer', 'min:0', 'max:100000'],
            'min_price'             => ['nullable', 'numeric', 'min:0'],
            'max_price'             => ['nullable', 'numeric', 'min:0'],
            'exclude_keywords'      => ['array'],
            'exclude_keywords.*'    => ['string', 'max:100'],
            'required_attributes'   => ['array'],
            'required_attributes.*' => ['string', 'in:' . implode(',', self::ATTRIBUTES)],
            'stock_behaviour'       => ['required', 'in:' . implode(',', self::STOCK_BEHAVIOURS)],
            'apply_vat'             => ['boolean'],
            'vat_rate'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price_rounding'        => ['nullable', 'in:' . implode(',', self::PRICE_ROUNDINGS)],
        ]);

        // Cohérence min/max.
        if (isset($validated['min_price'], $validated['max_price'])
            && $validated['max_price'] !== null && $validated['min_price'] !== null
            && $validated['max_price'] < $validated['min_price']) {
            return response()->json([
                'success' => false,
                'message' => 'Le prix maximum doit être supérieur ou égal au prix minimum.',
            ], 422);
        }

        // Normalise les mots-clés (trim, non vides, dédoublonnés, minuscules).
        if (isset($validated['exclude_keywords'])) {
            $validated['exclude_keywords'] = collect($validated['exclude_keywords'])
                ->map(fn ($k) => trim(mb_strtolower((string) $k)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        if (isset($validated['required_attributes'])) {
            $validated['required_attributes'] = array_values(array_unique($validated['required_attributes']));
        }

        $filter = ImportFilter::current();
        $filter->fill($validated)->save();

        return response()->json([
            'success' => true,
            'message' => 'Filtres d\'import enregistrés.',
            'filters' => $this->shape($filter->fresh()),
        ]);
    }

    /**
     * Overrides par catégorie : chaque catégorie mappée peut surcharger le stock
     * minimum et la fourchette de prix. Stockés sur category_mappings.
     */
    public function categoryOverrides(): JsonResponse
    {
        $mappings = CategoryMapping::query()
            ->where('ignored', false)
            ->whereNotNull('ps_category_id')
            ->get();

        $names = $mappings->isEmpty() ? collect()
            : TDSCategory::whereIn('code', $mappings->pluck('tds_category'))->pluck('name', 'code');

        $categories = $mappings->map(fn (CategoryMapping $m) => [
            'tds_category'       => $m->tds_category,
            'name'               => $names[$m->tds_category] ?? $m->tds_category,
            'ps_category_id'     => $m->ps_category_id,
            'min_stock_override' => $m->min_stock_override,
            'min_price_override' => $m->min_price_override !== null ? (float) $m->min_price_override : null,
            'max_price_override' => $m->max_price_override !== null ? (float) $m->max_price_override : null,
            'has_override'       => $m->min_stock_override !== null
                || $m->min_price_override !== null
                || $m->max_price_override !== null,
        ])->sortBy('name')->values()->all();

        return response()->json(['success' => true, 'categories' => $categories]);
    }

    public function updateCategoryOverride(Request $request, string $code): JsonResponse
    {
        $mapping = CategoryMapping::query()->where('tds_category', $code)->first();
        if (! $mapping) {
            return response()->json(['success' => false, 'message' => 'Catégorie non mappée.'], 404);
        }

        $validated = $request->validate([
            'min_stock_override' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'min_price_override' => ['nullable', 'numeric', 'min:0'],
            'max_price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $mapping->min_stock_override = $validated['min_stock_override'] ?? null;
        $mapping->min_price_override = $validated['min_price_override'] ?? null;
        $mapping->max_price_override = $validated['max_price_override'] ?? null;
        $mapping->save();

        return response()->json(['success' => true, 'message' => 'Override enregistré.']);
    }

    public function deleteCategoryOverride(string $code): JsonResponse
    {
        $mapping = CategoryMapping::query()->where('tds_category', $code)->first();
        if (! $mapping) {
            return response()->json(['success' => false, 'message' => 'Catégorie non mappée.'], 404);
        }

        $mapping->min_stock_override = null;
        $mapping->min_price_override = null;
        $mapping->max_price_override = null;
        $mapping->save();

        return response()->json(['success' => true, 'message' => 'Override supprimé.']);
    }

    private function shape(ImportFilter $f): array
    {
        return [
            'min_stock'           => (int) $f->min_stock,
            'min_price'           => $f->min_price !== null ? (float) $f->min_price : null,
            'max_price'           => $f->max_price !== null ? (float) $f->max_price : null,
            'exclude_keywords'    => $f->exclude_keywords ?? [],
            'required_attributes' => $f->required_attributes ?? [],
            'stock_behaviour'     => $f->stock_behaviour ?? 'disable',
            'apply_vat'           => (bool) $f->apply_vat,
            'vat_rate'            => $f->vat_rate !== null ? (float) $f->vat_rate : 20.0,
            'price_rounding'      => $f->price_rounding ?? 'none',
        ];
    }
}
