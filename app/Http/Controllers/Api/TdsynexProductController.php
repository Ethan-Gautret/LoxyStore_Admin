<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Models\TDSynexProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TdsynexProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $pageSize = max(1, min(100, (int) $request->integer('pageSize', 100)));
        $search = $request->string('search')->toString() ?: null;
        $source = $request->string('source')->toString() ?: 'remote';
        $fetchAll = $request->boolean('all');
        $refresh = $request->boolean('refresh');

        if ($source === 'local') {
            $mappedCategoryCodes = $this->mappedCategoryCodes();
            $mappedSet           = array_flip($mappedCategoryCodes);
            $filterMode          = $request->string('filter')->toString() ?: 'all';

            $query = TDSynexProduct::query();

            // "mapped" filter: restrict to categories that have an active PS mapping
            if ($filterMode === 'mapped') {
                if ($mappedCategoryCodes === []) {
                    return response()->json([
                        'data'         => [],
                        'count'        => 0,
                        'page'         => $page,
                        'pageSize'     => $pageSize,
                        'totalPages'   => 1,
                        'totalResults' => 0,
                        'source'       => 'local',
                        'filter'       => 'mapped',
                        'mapped_codes' => [],
                    ]);
                }
                $query->whereIn('category_tds', $mappedCategoryCodes);
            }

            if ($search) {
                $searchTerm = mb_strtolower(trim($search));
                $query->where(function ($builder) use ($searchTerm) {
                    $builder->whereRaw('LOWER(sku) like ?', ['%' . $searchTerm . '%'])
                        ->orWhereRaw('LOWER(name) like ?', ['%' . $searchTerm . '%'])
                        ->orWhereRaw('LOWER(manufacturer) like ?', ['%' . $searchTerm . '%'])
                        ->orWhereRaw('LOWER(category_tds) like ?', ['%' . $searchTerm . '%'])
                        ->orWhereRaw('LOWER(ean) like ?', ['%' . $searchTerm . '%'])
                        ->orWhereRaw('LOWER(description) like ?', ['%' . $searchTerm . '%']);
                });
            }

            $totalResults = (int) $query->count();
            $totalPages   = max(1, (int) ceil($totalResults / $pageSize));

            $products = $query
                ->orderByRaw('CASE WHEN stock_qty IS NULL THEN 2 WHEN stock_qty <= 0 THEN 1 ELSE 0 END ASC')
                ->orderByDesc('stock_qty')
                ->orderByDesc('updated_at')
                ->forPage($page, $pageSize)
                ->get()
                ->map(fn (TDSynexProduct $product) => $this->formatLocalProduct($product, $mappedSet))
                ->values()
                ->all();

            return response()->json([
                'data'         => $products,
                'count'        => count($products),
                'page'         => $page,
                'pageSize'     => $pageSize,
                'totalPages'   => $totalPages,
                'totalResults' => $totalResults,
                'source'       => 'local',
                'filter'       => $filterMode,
                'mapped_codes' => $mappedCategoryCodes,
            ]);
        }

        // Remote TD SYNNEX fetching has been removed. If caller requested remote source,
        // return an empty response indicating the feature is disabled.
        if ($source !== 'local') {
            return response()->json([
                'data' => [],
                'count' => 0,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => 0,
                'totalResults' => 0,
                'source' => 'remote_disabled',
            ]);
        }
    }

    /**
     * Import (synchronously) all products of every mapped category from the
     * TD SYNNEX catalogue into the local DB, so the Products page can display
     * them. Paging is sequential (one request at a time) inside
     * CategoryController::syncTdsynexCategoryProducts, which avoids the HTTP 500
     * the API returns under concurrent load.
     */
    public function sync(): JsonResponse
    {
        // Importing several thousand products page-by-page can take a few minutes.
        @set_time_limit(0);

        $codes = $this->mappedCategoryCodes();

        if ($codes === []) {
            return response()->json([
                'ok'           => true,
                'total'        => 0,
                'per_category' => [],
                'message'      => 'Aucune catégorie mappée à synchroniser.',
            ]);
        }

        $categoryController = app(CategoryController::class);
        $perCategory = [];
        $total = 0;

        foreach ($codes as $code) {
            try {
                $result = $categoryController->syncTdsynexCategoryProducts($code);
                $imported = (int) ($result['total'] ?? 0);
                $perCategory[$code] = $imported;
                $total += $imported;
            } catch (\Throwable $e) {
                Log::error('tdsynnex-products/sync: category import failed', [
                    'category' => $code,
                    'error'    => $e->getMessage(),
                ]);
                $perCategory[$code] = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok'           => true,
            'total'        => $total,
            'per_category' => $perCategory,
        ]);
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    // Integration with TD SYNNEX removed: payload/catalogue helpers deleted.

    private function formatLocalProduct(TDSynexProduct $product, array $mappedSet = []): array
    {
        $stockQty     = $product->stock_qty;
        $hasStockInfo = $stockQty !== null;
        $isOutOfStock = $hasStockInfo && ((int) $stockQty <= 0);
        $categoryCode = trim((string) ($product->category_tds ?? ''));

        return [
            'id'           => $product->id,
            'sku'          => $product->sku,
            'name'         => $product->name,
            'manufacturer' => $product->manufacturer,
            'category_tds' => $product->category_tds,
            'category_mapped' => $categoryCode !== '' && isset($mappedSet[$categoryCode]),
            'ean'          => $product->ean,
            'cost_price'   => $product->cost_price,
            'stock_qty'    => $hasStockInfo ? (int) $stockQty : null,
            'weight'       => $product->weight,
            'description'  => $product->description,
            'is_active'    => (bool) $product->is_active,
            'status'       => $product->is_active ? ($isOutOfStock ? 'Rupture' : 'Actif') : 'Inactif',
            'status_tone'  => $product->is_active ? ($isOutOfStock ? 'warning' : 'success') : 'muted',
            'stock_tone'   => $isOutOfStock ? 'danger' : ($hasStockInfo ? 'success' : 'muted'),
            'fetched_at'   => $product->fetched_at?->toIso8601String(),
            'updated_at'   => $product->updated_at?->toIso8601String(),
        ];
    }

    

    // Remote product retrieval and syncing removed.

    /**
     * @return array<int, string>
     */
    private function mappedCategoryCodes(): array
    {
        return CategoryMapping::query()
            ->where('active', true)
            ->where('ignored', false)
            ->whereNotNull('ps_category_id')
            ->pluck('tds_category')
            ->filter(fn ($category) => is_string($category) && trim($category) !== '')
            ->map(fn (string $category) => trim($category))
            ->unique()
            ->values()
            ->all();
    }

}