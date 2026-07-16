<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Models\TDSCategory;
use App\Models\TDSynexProduct;
use App\Support\CacheIndex;
use App\Support\CacheStoreResolver;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

use App\Jobs\SyncTdsynexCategoryProducts as SyncTdsynexCategoryProductsJob;
class CategoryController extends Controller
{

    private function fetchTdsynexCategoryCount(string $catalogueUrl, string $token, string $categoryCode): int
    {
        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->withoutVerifying()
                ->acceptJson()
                ->asJson()
                ->post($catalogueUrl, [
                    'class' => $categoryCode,
                    'page' => 1,
                    'pageSize' => 1,
                    'includePrice' => false,
                    'includeStock' => false,
                ]);

            if (! $response->ok()) {
                return 0;
            }

                return (int) ($response->json('totalResults')
                    ?? $response->json('total')
                    ?? $response->json('totalProducts')
                    ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function tdsCategories(): JsonResponse
    {
        $categoryModels = TDSCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $codes = $categoryModels->pluck('code')->all();

        // Local DB counts (fallback)
        $dbCounts = collect();
        try {
            if ($codes !== []) {
                $dbCounts = TDSynexProduct::query()
                    ->whereIn('category_tds', $codes)
                    ->selectRaw('category_tds, COUNT(*) as product_count')
                    ->groupBy('category_tds')
                    ->pluck('product_count', 'category_tds');
            }
        } catch (\Throwable) {}

        $mappings = collect();
        try {
            if ($codes !== []) {
                $mappings = CategoryMapping::whereIn('tds_category', $codes)
                    ->get()
                    ->keyBy('tds_category');
            }
        } catch (\Throwable) {}

        $categories = [];
        foreach ($categoryModels as $category) {
            $code = $category->code;
            $count = (int) ($dbCounts[$code] ?? 0);

            $categories[] = [
                'id'            => $code,
                'name'          => $category->name,
                'code'          => $code,
                'product_count' => $count,
                'mapping'       => $mappings->has($code) ? [
                    'ps_category_id'     => $mappings[$code]->ps_category_id,
                    'margin_override'    => $mappings[$code]->margin_override,
                    'min_stock_override' => $mappings[$code]->min_stock_override,
                    'active'             => $mappings[$code]->active,
                    'ignored'            => $mappings[$code]->ignored,
                ] : null,
            ];
        }

        usort($categories, fn ($a, $b) => ($a['code'] ?? '') <=> ($b['code'] ?? ''));

        return response()->json([
            'success'          => true,
            'categories'       => $categories,
            'total_products'   => array_sum(array_column($categories, 'product_count')),
            'total_categories' => count($categories),
            'source'           => 'db',
        ]);
    }

    public function saveMapping(Request $request): JsonResponse
    {
        try {
            Log::debug('saveMapping called', $request->all());
        } catch (\Throwable) {}

        $validated = $request->validate([
            'tds_category'       => ['required', 'string', 'max:200'],
            'ps_category_id'     => ['nullable', 'integer'],
            'margin_override'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock_override' => ['nullable', 'integer', 'min:0'],
            'ignored'            => ['boolean'],
        ]);

        $ignored = (bool) ($validated['ignored'] ?? false);
        $isMapped = ! $ignored && ! empty($validated['ps_category_id']);
        $mappingData = null;
        $message = 'Catégorie non mappée. Les données locales associées ont été supprimées.';
        $syncResult = null;

        if ($isMapped) {
            try {
                $mapping = CategoryMapping::updateOrCreate(
                    ['tds_category' => $validated['tds_category']],
                    [
                        'ps_category_id'     => $validated['ps_category_id'],
                        'margin_override'    => $validated['margin_override'] ?? null,
                        'min_stock_override' => $validated['min_stock_override'] ?? null,
                        'active'             => true,
                        'ignored'            => false,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('saveMapping: failed to updateOrCreate mapping', ['error' => $e->getMessage(), 'payload' => $validated]);
                throw $e;
            }

            $mappingData = [
                'ps_category_id'     => $mapping->ps_category_id,
                'margin_override'    => $mapping->margin_override,
                'min_stock_override' => $mapping->min_stock_override,
                'active'             => $mapping->active,
                'ignored'            => $mapping->ignored,
            ];

                try {
                    // Import the category's products automatically right after the
                    // response is sent to the browser - no queue worker needed. The
                    // user gets an instant "mapped" reply while the (possibly long)
                    // import runs in the background of this same request.
                    SyncTdsynexCategoryProductsJob::dispatchAfterResponse($validated['tds_category']);
                    $syncResult = ['ok' => true, 'queued' => true];
                    $message = 'Catégorie mappée. L\'import des produits démarre automatiquement en arrière-plan (cela peut prendre quelques minutes pour les grosses catégories).';
                } catch (\Throwable $exception) {
                    $syncResult = [
                        'ok' => false,
                        'message' => $exception->getMessage(),
                    ];

                    $message = 'Catégorie mappée, mais impossible d\'enclencher l\'import: ' . $exception->getMessage();
                }
        } else {
            // Collect the SKUs BEFORE wiping the local rows so we can also remove
            // the corresponding products from PrestaShop: a category that becomes
            // "non mappée" must no longer expose its products in the shop.
            $skusToRemove = [];
            try {
                $skusToRemove = TDSynexProduct::query()
                    ->where('category_tds', $validated['tds_category'])
                    ->pluck('sku')
                    ->all();
            } catch (\Throwable) {}

            $psDeleted = 0;
            $psDeleteErrors = 0;
            if ($skusToRemove !== []) {
                try {
                    $psResult = app(CategorySyncController::class)
                        ->deletePrestashopProductsBySku($skusToRemove);
                    $psDeleted = (int) ($psResult['deleted'] ?? 0);
                    $psDeleteErrors = count($psResult['errors'] ?? []);
                } catch (\Throwable $e) {
                    Log::error('saveMapping: failed to delete PrestaShop products', ['error' => $e->getMessage(), 'payload' => $validated]);
                }
            }

            try {
                CategoryMapping::where('tds_category', $validated['tds_category'])->delete();
            } catch (\Throwable $e) {
                Log::error('saveMapping: failed to delete mapping', ['error' => $e->getMessage(), 'payload' => $validated]);
            }

            try {
                $deletedProducts = TDSynexProduct::query()
                    ->where('category_tds', $validated['tds_category'])
                    ->delete();
            } catch (\Throwable $e) {
                Log::error('saveMapping: failed to delete local products', ['error' => $e->getMessage(), 'payload' => $validated]);
                $deletedProducts = 0;
            }

            $syncResult = [
                'ok' => true,
                'deleted' => $deletedProducts,
                'prestashop_deleted' => $psDeleted,
                'prestashop_errors' => $psDeleteErrors,
            ];

            $message = sprintf(
                'Catégorie non mappée. %d produit(s) local(aux) supprimé(s), %d produit(s) supprimé(s) de PrestaShop%s.',
                (int) $deletedProducts,
                $psDeleted,
                $psDeleteErrors > 0 ? sprintf(' (%d en erreur)', $psDeleteErrors) : ''
            );
        }

        try {
            $this->flushApiResponseCache();
        } catch (\Throwable) {}

        return response()->json([
            'success' => true,
            'message' => $message,
            'mapping' => $mappingData,
            'sync' => $syncResult,
        ]);
    }

    /**
     * Reconcile counts between TD SYNNEX API and local DB for mapped categories.
     * Dispatch a background sync job for categories where the DB has fewer products than the API reports.
     */
    // Reconcile functionality removed - sync responsibilities have been disabled.

    // ── Private helpers ────────────────────────────────────────────────────────


    public function syncTdsynexCategoryProducts(string $categoryCode): array
    {
        // A large category (e.g. ~10000 products = 100 pages) can take a couple
        // of minutes even when paged concurrently, so lift any execution limit.
        @set_time_limit(0);

        $payload = $this->tdsynexSettings();

        if ($payload === null) {
            throw new \RuntimeException('Configuration TDSynex introuvable.');
        }

        $token = $this->getTdsynexAccessToken($payload);

        if (! $token) {
            throw new \RuntimeException('Impossible d\'obtenir un token TDSynex. Vérifiez la configuration d\'intégration.');
        }

        $catalogueUrl = $this->tdsynexCatalogueUrl($payload);

        if ($catalogueUrl === null) {
            throw new \RuntimeException('URL catalogue TDSynex invalide.');
        }

        // Track import progress so the UI can show "Import en cours N/total" and
        // only enable the PrestaShop push once every product has been imported.
        $expectedTotal = $this->fetchTdsynexCategoryCount($catalogueUrl, $token, $categoryCode);
        $this->writeImportStatus($categoryCode, 'running', $expectedTotal > 0 ? $expectedTotal : null);

        try {

        $pageSize = 100;
        $maxPages = 100;   // A single query is capped by the API at 10000 results (= 100 pages).
        $batchSize = 5;    // TD SYNNEX returns HTTP 500/401 above ~5 concurrent requests.

        $allSkus = [];
        $created = 0;
        $updated = 0;

        // A class query is capped at 10000 results, but a class can hold more
        // (e.g. COMACCSER ~13000). Paging the class alone both duplicates and drops
        // products. To get everything, we import per manufacturer - each
        // manufacturer is well under 10000 and therefore fully reachable. We first
        // discover the manufacturers present in the class.
        $manufacturers = $this->discoverTdsynexManufacturers(
            $catalogueUrl, $token, $payload, $categoryCode, $pageSize, $maxPages, $batchSize
        );

        if ($manufacturers === []) {
            // Fallback (no manufacturer facet available): import the class directly.
            $this->importTdsynexQuery(
                $categoryCode, null, $catalogueUrl, $token, $payload,
                $pageSize, $maxPages, $batchSize, $allSkus, $created, $updated
            );
        } else {
            foreach ($manufacturers as $manufacturer) {
                $this->importTdsynexQuery(
                    $categoryCode, $manufacturer, $catalogueUrl, $token, $payload,
                    $pageSize, $maxPages, $batchSize, $allSkus, $created, $updated
                );
            }
        }

        // Remove products that are no longer present in the catalogue for this category.
        $uniqueSkus = array_values(array_unique($allSkus));

        if ($uniqueSkus === []) {
            TDSynexProduct::query()->where('category_tds', $categoryCode)->delete();
        } else {
            TDSynexProduct::query()
                ->where('category_tds', $categoryCode)
                ->whereNotIn('sku', $uniqueSkus)
                ->delete();
        }

        $this->writeImportStatus($categoryCode, 'done');

        return [
            'ok'            => true,
            'total'         => count($uniqueSkus),
            'created'       => $created,
            'updated'       => $updated,
            'manufacturers' => count($manufacturers),
        ];

        } catch (\Throwable $e) {
            $this->writeImportStatus($categoryCode, 'failed');
            throw $e;
        }
    }


    /**
     * Import every product matching (class [+ manufacturer]) into the DB. Page 1
     * is fetched first to learn the page count; the rest are fetched in concurrent
     * batches and upserted incrementally. Failed pages (TD SYNNEX intermittently
     * returns 401/500 under load) are retried with a fresh token and a backoff.
     *
     * @param array<int, string> $allSkus
     */
    private function importTdsynexQuery(string $categoryCode, ?string $manufacturer, string $catalogueUrl, string &$token, array $payload, int $pageSize, int $maxPages, int $batchSize, array &$allSkus, int &$created, int &$updated): void
    {
        $first = $this->fetchTdsynexCatalogPage($catalogueUrl, $token, $categoryCode, 1, $pageSize, $manufacturer, true);

        if ($first['status'] === 401) {
            $refreshed = $this->getTdsynexAccessToken($payload, true);
            if ($refreshed) {
                $token = $refreshed;
            }
            $first = $this->fetchTdsynexCatalogPage($catalogueUrl, $token, $categoryCode, 1, $pageSize, $manufacturer, true);
        }

        if ($first['status'] !== 200) {
            // Skip this query rather than aborting the whole category import.
            try {
                Log::warning('TDSynex import: query failed', [
                    'category'     => $categoryCode,
                    'manufacturer' => $manufacturer,
                    'status'       => $first['status'],
                ]);
            } catch (\Throwable) {}
            return;
        }

        $this->upsertTdsynexBatch($categoryCode, $first['products'], $allSkus, $created, $updated);

        $totalPages = min($maxPages, max(1, (int) $first['totalPages']));
        $pending = $totalPages >= 2 ? range(2, $totalPages) : [];
        $maxAttempts = 4;

        for ($attempt = 0; $pending !== [] && $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // 401 is the dominant failure, so mint a fresh token before retrying.
                $refreshed = $this->getTdsynexAccessToken($payload, true);
                if ($refreshed) {
                    $token = $refreshed;
                }
                usleep(750000); // 0.75s backoff to let any rate-limit window clear
            }

            $pending = $this->fetchAndUpsertPages(
                $catalogueUrl, $token, $categoryCode, $manufacturer, $pending,
                $pageSize, $batchSize, $allSkus, $created, $updated
            );
        }

        if ($pending !== []) {
            try {
                Log::warning('TDSynex import: pages still failing after retries', [
                    'category'     => $categoryCode,
                    'manufacturer' => $manufacturer,
                    'pages'        => $pending,
                ]);
            } catch (\Throwable) {}
        }
    }


    /**
     * Discover the distinct manufacturer names present in a class by paging the
     * (capped) class window with a lightweight request. Best-effort: a failed page
     * only risks missing a rare manufacturer, so failures are retried but never abort.
     *
     * @return array<int, string>
     */
    private function discoverTdsynexManufacturers(string $catalogueUrl, string &$token, array $payload, string $categoryCode, int $pageSize, int $maxPages, int $batchSize): array
    {
        $first = $this->fetchTdsynexCatalogPage($catalogueUrl, $token, $categoryCode, 1, $pageSize, null, false);

        if ($first['status'] === 401) {
            $refreshed = $this->getTdsynexAccessToken($payload, true);
            if ($refreshed) {
                $token = $refreshed;
            }
            $first = $this->fetchTdsynexCatalogPage($catalogueUrl, $token, $categoryCode, 1, $pageSize, null, false);
        }

        if ($first['status'] !== 200) {
            return [];
        }

        $names = [];
        $collect = static function (array $products) use (&$names): void {
            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }
                $name = trim((string) ($product['manufacturer'] ?? ''));
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        };

        $collect($first['products']);

        $totalPages = min($maxPages, max(1, (int) $first['totalPages']));
        $pending = $totalPages >= 2 ? range(2, $totalPages) : [];
        $maxAttempts = 3;

        for ($attempt = 0; $pending !== [] && $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                $refreshed = $this->getTdsynexAccessToken($payload, true);
                if ($refreshed) {
                    $token = $refreshed;
                }
                usleep(750000);
            }

            $failed = [];

            foreach (array_chunk($pending, $batchSize) as $batch) {
                $responses = Http::pool(function (Pool $pool) use ($batch, $catalogueUrl, $token, $categoryCode, $pageSize) {
                    foreach ($batch as $page) {
                        $pool->as((string) $page)
                            ->timeout(30)
                            ->withToken($token)
                            ->withoutVerifying()
                            ->acceptJson()
                            ->asJson()
                            ->post($catalogueUrl, $this->tdsynexCatalogBody($categoryCode, $page, $pageSize, null, false));
                    }
                });

                foreach ($batch as $page) {
                    $response = $responses[(string) $page] ?? null;
                    if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
                        $failed[] = $page;
                        continue;
                    }
                    $products = $response->json('products');
                    if (! is_array($products)) {
                        $products = $response->json('data', []);
                    }
                    $collect(is_array($products) ? $products : []);
                }
            }

            $pending = $failed;
        }

        return array_keys($names);
    }


    /**
     * Build the catalogue request body, optionally filtered by manufacturer and
     * optionally including price/stock (price/stock make the response heavier, so
     * discovery passes false).
     *
     * @return array<string, mixed>
     */
    private function tdsynexCatalogBody(string $categoryCode, int $page, int $pageSize, ?string $manufacturer, bool $withPricing): array
    {
        $body = [
            'class' => $categoryCode,
            'page' => $page,
            'pageSize' => $pageSize,
            'includePrice' => $withPricing,
            'includeStock' => $withPricing,
        ];

        if ($manufacturer !== null && $manufacturer !== '') {
            $body['manufacturer'] = $manufacturer;
        }

        return $body;
    }


    /**
     * Fetch the given catalogue pages in concurrent batches and upsert each
     * batch. Returns the list of pages that failed (so the caller can retry).
     *
     * @param array<int, int> $pages
     * @param array<int, string> $allSkus
     * @return array<int, int> pages that failed
     */
    private function fetchAndUpsertPages(string $catalogueUrl, string $token, string $categoryCode, ?string $manufacturer, array $pages, int $pageSize, int $batchSize, array &$allSkus, int &$created, int &$updated): array
    {
        $failedPages = [];

        foreach (array_chunk($pages, $batchSize) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $catalogueUrl, $token, $categoryCode, $pageSize, $manufacturer) {
                foreach ($batch as $page) {
                    $pool->as((string) $page)
                        ->timeout(40)
                        ->withToken($token)
                        ->withoutVerifying()
                        ->acceptJson()
                        ->asJson()
                        ->post($catalogueUrl, $this->tdsynexCatalogBody($categoryCode, $page, $pageSize, $manufacturer, true));
                }
            });

            $batchProducts = [];

            foreach ($batch as $page) {
                $response = $responses[(string) $page] ?? null;

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
                    $failedPages[] = $page;
                    continue;
                }

                $pageProducts = $response->json('products');
                if (! is_array($pageProducts)) {
                    $pageProducts = $response->json('data', []);
                }

                foreach ($pageProducts as $product) {
                    if (is_array($product)) {
                        $batchProducts[] = $product;
                    }
                }
            }

            if ($batchProducts !== []) {
                $this->upsertTdsynexBatch($categoryCode, $batchProducts, $allSkus, $created, $updated);
            }
        }

        return $failedPages;
    }


    /**
     * Fetch a single catalogue page. Never throws on HTTP errors - returns the
     * status so the caller can decide (refresh token on 401, skip on 5xx, etc.).
     *
     * @return array{status:int, body:string, products:array, totalPages:int}
     */
    private function fetchTdsynexCatalogPage(string $catalogueUrl, string $token, string $categoryCode, int $page, int $pageSize, ?string $manufacturer = null, bool $withPricing = true): array
    {
        try {
            $response = Http::timeout(40)
                ->withToken($token)
                ->withoutVerifying()
                ->acceptJson()
                ->asJson()
                ->post($catalogueUrl, $this->tdsynexCatalogBody($categoryCode, $page, $pageSize, $manufacturer, $withPricing));
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => $e->getMessage(), 'products' => [], 'totalPages' => 0];
        }

        $products = $response->json('products');
        if (! is_array($products)) {
            $products = $response->json('data', []);
        }

        return [
            'status'     => $response->status(),
            'body'       => trim($response->body()),
            'products'   => is_array($products) ? $products : [],
            'totalPages' => (int) ($response->json('totalPages') ?? 0),
        ];
    }


    /**
     * Upsert one batch of remote products into the DB inside a single small
     * transaction. SKUs/created/updated counters are accumulated by reference.
     * Stale-product deletion is the caller's responsibility (done once at the end).
     *
     * @param array<int, array<string, mixed>> $remoteProducts
     * @param array<int, string> $allSkus
     */
    private function upsertTdsynexBatch(string $categoryCode, array $remoteProducts, array &$allSkus, int &$created, int &$updated): void
    {
        if ($remoteProducts === []) {
            return;
        }

        // Filtres d'import (globaux) + overrides éventuels de la catégorie.
        $filter = \App\Models\ImportFilter::current();
        $mapping = CategoryMapping::query()->where('tds_category', $categoryCode)->first();
        $override = $mapping ? [
            'min_stock' => $mapping->min_stock_override,
            'min_price' => $mapping->min_price_override,
            'max_price' => $mapping->max_price_override,
        ] : null;

        DB::transaction(function () use ($remoteProducts, $categoryCode, $filter, $override, &$allSkus, &$created, &$updated): void {
            foreach ($remoteProducts as $remoteProduct) {
                $sku = trim((string) ($remoteProduct['sku'] ?? $remoteProduct['id'] ?? data_get($remoteProduct, 'tdsynnexPartNumber') ?? ''));

                if ($sku === '') {
                    continue;
                }

                $name = trim((string) ($remoteProduct['name'] ?? data_get($remoteProduct, 'productDescription') ?? ''));

                if ($name === '') {
                    $name = $sku;
                }

                // Column limits: name varchar(500), manufacturer varchar(150), ean varchar(20).
                $name = mb_substr($name, 0, 500);

                $manufacturer = trim((string) ($remoteProduct['manufacturer'] ?? data_get($remoteProduct, 'manufacturer_name') ?? data_get($remoteProduct, 'brand') ?? ''));
                $manufacturer = mb_substr($manufacturer, 0, 150);

                $eanValue = $remoteProduct['ean'] ?? data_get($remoteProduct, 'upcEan');
                $ean = is_scalar($eanValue) ? mb_substr((string) $eanValue, 0, 20) : null;

                $description = $remoteProduct['description'] ?? data_get($remoteProduct, 'productDescription');
                $rawPayload = $remoteProduct['raw_payload'] ?? $remoteProduct;
                // "Bon stock" = quantité disponible TOTALE chez TD SYNNEX (tous
                // entrepôts confondus), et non le seul entrepôt local
                // (quantityAvailableLocal, souvent à 0 alors qu'il y a du stock ailleurs).
                $stockQty = data_get($remoteProduct, 'stock.quantityAvailableTotal');

                if ($stockQty === null) {
                    $stockQty = data_get($remoteProduct, 'stock.quantityAvailableLocal')
                        ?? ($remoteProduct['stock_qty'] ?? null);
                }

                $costPrice = $remoteProduct['cost_price']
                    ?? data_get($remoteProduct, 'costPrice')
                    ?? data_get($remoteProduct, 'purchasePrice')
                    ?? data_get($remoteProduct, 'price.customerPrice')
                    ?? data_get($remoteProduct, 'price.listPrice');
                $weight = $remoteProduct['weight'] ?? data_get($remoteProduct, 'shippingWeight');
                $isActive = array_key_exists('is_active', $remoteProduct)
                    ? (bool) $remoteProduct['is_active']
                    : ! in_array(strtolower((string) ($remoteProduct['productStatusCode'] ?? 'active')), ['inactive', 'discontinued', 'deleted'], true);

                // Filtres d'import : exclure (ne pas importer) ou désactiver le produit.
                // Un produit exclu n'est pas ajouté à $allSkus → purgé du catalogue local.
                $decision = \App\Support\ImportFilterEvaluator::evaluate([
                    'name'        => $name,
                    'ean'         => $ean,
                    'weight'      => is_numeric($weight) ? (float) $weight : null,
                    'description' => is_string($description) ? $description : null,
                    'cost_price'  => is_numeric($costPrice) ? (float) $costPrice : 0.0,
                    'stock_qty'   => is_numeric($stockQty) ? (int) $stockQty : 0,
                ], $filter, $override);

                if (! $decision['keep']) {
                    continue;
                }
                $isActive = $isActive && $decision['active'];

                $hash = sha1(json_encode([
                    'sku' => $sku,
                    'name' => $name,
                    'manufacturer' => $manufacturer,
                    'category' => $categoryCode,
                    'ean' => $ean,
                    'cost_price' => $costPrice,
                    'stock_qty' => $stockQty,
                    'weight' => $weight,
                    'description' => $description,
                    'is_active' => $isActive,
                    'raw_payload' => $rawPayload,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $product = TDSynexProduct::updateOrCreate(
                    [
                        'sku' => $sku,
                        'category_tds' => $categoryCode,
                    ],
                    [
                        'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                        'name' => $name,
                        'ean' => $ean,
                        'cost_price' => is_numeric($costPrice) ? (float) $costPrice : 0,
                        'stock_qty' => is_numeric($stockQty) ? (int) $stockQty : 0,
                        'weight' => is_numeric($weight) ? (float) $weight : null,
                        'description' => is_string($description) ? $description : null,
                        'raw_payload' => $rawPayload,
                        'hash' => $hash,
                        'is_active' => $isActive,
                        'fetched_at' => now(),
                    ]
                );

                $allSkus[] = $sku;

                if ($product->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        });

        // Keep the import "alive" for the status endpoint (heartbeat), so a long
        // running import is not mistaken for a stalled one.
        $this->touchImportStatus($categoryCode);
    }


    private function tdsynexSettings(): ?array
    {
        $storePath = storage_path('app/integration-settings.json');

        if (! File::exists($storePath)) {
            return null;
        }

        $config = json_decode(File::get($storePath), true);
        $payload = $config['tdsynnex']['payload'] ?? null;

        if (! is_array($payload) || empty($payload['endpoint_url'])) {
            return null;
        }

        return $payload;
    }


    private function tdsynexCatalogueUrl(array $payload): ?string
    {
        $parsed = parse_url($payload['endpoint_url']);

        if (! is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        return sprintf(
            '%s://%s/%s/resellers/v1/products/catalogue',
            $parsed['scheme'],
            $parsed['host'],
            $payload['region'] ?? 'eu'
        );
    }


    private function getTdsynexAccessToken(array $payload, bool $forceRefresh = false): ?string
    {
        if (empty($payload['endpoint_url']) || empty($payload['client_id']) || empty($payload['client_secret'])) {
            return null;
        }
        $store = Cache::store(CacheStoreResolver::name());
        $cacheKey = $this->tdsynexTokenCacheKey($payload);

        if ($forceRefresh) {
            try {
                $store->forget($cacheKey);
            } catch (\Throwable) {}
        }

        return $store->remember(
            $cacheKey,
            now()->addMinutes(50),
            function () use ($payload) {
                try {
                    $response = Http::timeout(20)
                        ->withoutVerifying()
                        ->asForm()
                        ->post($payload['endpoint_url'], [
                            'grant_type' => 'client_credentials',
                            'client_id' => $payload['client_id'],
                            'client_secret' => $payload['client_secret'],
                        ]);

                    if (! $response->ok()) {
                        return null;
                    }

                    $data = $response->json();

                    return $data['access_token'] ?? null;
                } catch (\Throwable) {
                    return null;
                }
            }
        );
    }


    private function tdsynexTokenCacheKey(array $payload): string
    {
        return 'integration:tdsynnex:token:' . sha1(json_encode([
            $payload['endpoint_url'] ?? '',
            $payload['client_id'] ?? '',
            $payload['region'] ?? '',
        ], JSON_UNESCAPED_SLASHES));
    }

    public function tdsCategoryCounts(): JsonResponse
    {
        $categoryModels = TDSCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $codes = $categoryModels->pluck('code')->all();

        $counts = [];

        // First, try remote counts with per-category cache and concurrent requests.
        try {
            $payload = $this->tdsynexSettings();
            if (is_array($payload)) {
                $token = $this->getTdsynexAccessToken($payload);
                $catalogueUrl = $this->tdsynexCatalogueUrl($payload);

                if ($token && $catalogueUrl) {
                    $cacheStore = Cache::store(CacheStoreResolver::name());
                    $pendingCategories = [];

                    foreach ($categoryModels as $category) {
                        $code = $category->code;
                        $cacheKey = 'integration:tdsynnex:count:' . sha1($catalogueUrl . '|' . $code . '|' . ($payload['client_id'] ?? ''));

                        try {
                            $cachedCount = $cacheStore->get($cacheKey);
                        } catch (\Throwable) {
                            $cachedCount = null;
                        }

                        if ($cachedCount !== null) {
                            $counts[$code] = (int) $cachedCount;
                            continue;
                        }

                        $pendingCategories[] = [
                            'code' => $code,
                            'cache_key' => $cacheKey,
                        ];
                    }

                    if ($pendingCategories !== []) {
                        // TD SYNNEX returns HTTP 500 when hit with too many concurrent
                        // requests, so we batch the catalogue calls in small chunks.
                        // Transient failures (5xx / 429 / connection errors) are retried
                        // and NEVER cached - only genuine results (200, or 404 = the
                        // category does not exist) are stored, so a temporary glitch can
                        // not poison the cache with a wrong 0 for the next 6 hours.
                        $batchSize = 5;
                        // One concurrent pass + one retry is enough to grab whatever the
                        // API answers cheaply; the sequential net below guarantees the
                        // rest, so extra concurrent retries would only waste cold-start
                        // time thrashing against the API's concurrency limit.
                        $maxRetries = 1;
                        $remaining = $pendingCategories;

                        for ($attempt = 0; $attempt <= $maxRetries && $remaining !== []; $attempt++) {
                            $failed = [];
                            $sawAuthError = false;

                            foreach (array_chunk($remaining, $batchSize) as $batch) {
                                $responses = Http::pool(function (Pool $pool) use ($batch, $catalogueUrl, $token) {
                                    foreach ($batch as $category) {
                                        $pool->as($category['code'])
                                            ->timeout(12)
                                            ->withToken($token)
                                            ->withoutVerifying()
                                            ->acceptJson()
                                            ->asJson()
                                            ->post($catalogueUrl, [
                                                'class' => $category['code'],
                                                'page' => 1,
                                                'pageSize' => 1,
                                                'includePrice' => false,
                                                'includeStock' => false,
                                            ]);
                                    }
                                });

                                foreach ($batch as $category) {
                                    $code = $category['code'];
                                    $cacheKey = $category['cache_key'];
                                    $response = $responses[$code] ?? null;

                                    // Connection error (e.g. timeout): retry, do not cache.
                                    if (! $response instanceof \Illuminate\Http\Client\Response) {
                                        $failed[] = $category;
                                        continue;
                                    }

                                    $status = $response->status();

                                    if ($status === 401) {
                                        $sawAuthError = true;
                                        $failed[] = $category;
                                        continue;
                                    }

                                    if ($response->ok()) {
                                        $count = (int) ($response->json('totalResults')
                                            ?? $response->json('total')
                                            ?? $response->json('totalProducts')
                                            ?? 0);
                                        $counts[$code] = $count;
                                        try {
                                            $cacheStore->put($cacheKey, $count, now()->addHours(6));
                                        } catch (\Throwable) {}
                                        continue;
                                    }

                                    if ($status === 404) {
                                        // Category does not exist in the catalogue: genuinely 0.
                                        $counts[$code] = 0;
                                        try {
                                            $cacheStore->put($cacheKey, 0, now()->addHours(6));
                                        } catch (\Throwable) {}
                                        continue;
                                    }

                                    // 5xx / 429 / other: transient, retry without caching.
                                    $failed[] = $category;
                                }
                            }

                            // Refresh the token once before retrying auth failures.
                            if ($sawAuthError) {
                                $token = $this->getTdsynexAccessToken($payload, true);
                                if (! $token) {
                                    break;
                                }
                            }

                            $remaining = $failed;
                        }

                        // Final safety net: any category still unresolved after the
                        // concurrent batches is retried ONE AT A TIME. TD SYNNEX rejects
                        // requests under concurrency (500/401) but answers reliably when
                        // queried sequentially, so this recovers the stragglers (e.g. the
                        // AV*/HH* tail) that would otherwise be dropped and shown as
                        // "0 produit" in the UI via the DB fallback.
                        foreach ($remaining as $category) {
                            $code = $category['code'];
                            $cacheKey = $category['cache_key'];

                            $response = null;
                            for ($seqAttempt = 0; $seqAttempt < 2; $seqAttempt++) {
                                try {
                                    $response = Http::timeout(12)
                                        ->withToken($token)
                                        ->withoutVerifying()
                                        ->acceptJson()
                                        ->asJson()
                                        ->post($catalogueUrl, [
                                            'class' => $code,
                                            'page' => 1,
                                            'pageSize' => 1,
                                            'includePrice' => false,
                                            'includeStock' => false,
                                        ]);
                                } catch (\Throwable) {
                                    $response = null;
                                }

                                // Refresh the token once and retry this category on 401.
                                if ($response instanceof \Illuminate\Http\Client\Response && $response->status() === 401) {
                                    $token = $this->getTdsynexAccessToken($payload, true);
                                    if (! $token) {
                                        break;
                                    }
                                    continue;
                                }

                                break;
                            }

                            if (! $response instanceof \Illuminate\Http\Client\Response) {
                                continue; // still unreachable: leave unresolved (UI keeps DB fallback)
                            }

                            if ($response->ok()) {
                                $count = (int) ($response->json('totalResults')
                                    ?? $response->json('total')
                                    ?? $response->json('totalProducts')
                                    ?? 0);
                                $counts[$code] = $count;
                                try {
                                    $cacheStore->put($cacheKey, $count, now()->addHours(6));
                                } catch (\Throwable) {}
                            } elseif ($response->status() === 404) {
                                // Category does not exist in the catalogue: genuinely 0.
                                $counts[$code] = 0;
                                try {
                                    $cacheStore->put($cacheKey, 0, now()->addHours(6));
                                } catch (\Throwable) {}
                            }

                            // Small spacing to stay under the API's concurrency limit.
                            usleep(200000);
                        }

                        // Categories still unresolved keep no entry in $counts, so the
                        // frontend falls back to the last known DB value instead of 0.
                    }

                    return response()->json(['success' => true, 'counts' => $counts]);
                }
            }
        } catch (\Throwable) {
            // fall through to DB fallback
        }

        // Fallback: local DB counts
        try {
            $dbCounts = TDSynexProduct::query()
                ->whereIn('category_tds', $codes)
                ->selectRaw('category_tds, COUNT(*) as product_count')
                ->groupBy('category_tds')
                ->pluck('product_count', 'category_tds')
                ->all();

            foreach ($categoryModels as $category) {
                $code = $category->code;
                $counts[$code] = (int) ($dbCounts[$code] ?? 0);
            }

            return response()->json(['success' => true, 'counts' => $counts, 'source' => 'db']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Unable to compute counts', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Import progress for a category, consumed by the Categories page to show
     * "Import en cours N/total" and to gate the PrestaShop push button (only
     * enabled once the import is done). `imported` is the authoritative live DB
     * count; `status`/`total`/heartbeat come from the cache record written by the
     * import. A running import whose heartbeat is stale (process reaped) is
     * reported as `stalled` so the UI never blocks the push forever.
     */
    public function tdsImportStatus(string $code): JsonResponse
    {
        $imported = 0;
        try {
            $imported = (int) TDSynexProduct::query()->where('category_tds', $code)->count();
        } catch (\Throwable) {}

        $record = null;
        try {
            $record = Cache::store(CacheStoreResolver::name())->get($this->importStatusKey($code));
        } catch (\Throwable) {}

        $status    = is_array($record) ? ($record['status'] ?? null) : null;
        $total     = is_array($record) ? ($record['total'] ?? null) : null;
        $heartbeat = is_array($record) ? ($record['heartbeat'] ?? null) : null;

        // No status record (feature added after some imports, or cache evicted):
        // infer from the DB so the button is usable.
        if ($status === null) {
            $status = $imported > 0 ? 'done' : 'idle';
        }

        $stalled = $status === 'running'
            && $heartbeat !== null
            && (now()->timestamp - (int) $heartbeat) > 90;

        $running = $status === 'running' && ! $stalled;

        return response()->json([
            'success'  => true,
            'status'   => $status,
            'imported' => $imported,
            'total'    => $total !== null ? (int) $total : null,
            'running'  => $running,
            'stalled'  => $stalled,
            'done'     => $status === 'done',
        ]);
    }

    private function importStatusKey(string $code): string
    {
        return 'integration:tdsynnex:importstatus:' . sha1($code);
    }

    private function writeImportStatus(string $code, string $status, ?int $total = null): void
    {
        try {
            $store = Cache::store(CacheStoreResolver::name());
            $key = $this->importStatusKey($code);

            if ($total === null) {
                $existing = $store->get($key);
                $total = is_array($existing) ? ($existing['total'] ?? null) : null;
            }

            $store->put($key, [
                'status'    => $status,        // running | done | failed
                'total'     => $total,
                'heartbeat' => now()->timestamp,
            ], now()->addDay());
        } catch (\Throwable) {}
    }

    private function touchImportStatus(string $code): void
    {
        try {
            $store = Cache::store(CacheStoreResolver::name());
            $key = $this->importStatusKey($code);
            $existing = $store->get($key);

            if (! is_array($existing) || ($existing['status'] ?? null) !== 'running') {
                return;
            }

            $existing['heartbeat'] = now()->timestamp;
            $store->put($key, $existing, now()->addDay());
        } catch (\Throwable) {}
    }

    private function flushApiResponseCache(): void
    {
        $storeName = CacheStoreResolver::name();

        if ($storeName === 'redis') {
            try {
                Cache::store($storeName)->tags(['api'])->flush();
            } catch (\Throwable) {}
        }

        foreach (CacheIndex::all() as $cacheKey => $metadata) {
            if (! is_string($cacheKey) || ! str_starts_with($cacheKey, 'api-response:')) {
                continue;
            }
            try {
                Cache::store($storeName)->forget($cacheKey);
            } catch (\Throwable) {}
            try {
                CacheIndex::remove($cacheKey);
            } catch (\Throwable) {}
        }
    }

    // Remote TD SYNNEX API helpers removed.
}
