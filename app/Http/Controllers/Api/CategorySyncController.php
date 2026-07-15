<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoryMapping;
use App\Models\SyncLog;
use App\Models\TDSynexProduct;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CategorySyncController extends Controller
{
    // ── Public endpoint ────────────────────────────────────────────────────────

    /**
     * Push every local product of a mapped TD SYNNEX category to PrestaShop,
     * creating or updating it (matched by SKU = product reference) and setting its
     * stock quantity. Selling price = cost_price * (1 + margin%).
     *
     * Accepts an optional ?limit=N query param to push only the first N products
     * (useful for a safe verification run before a full push).
     */
    public function push(Request $request, string $code): JsonResponse
    {
        // Pushing thousands of products is one HTTP round-trip each, so lift limits.
        @set_time_limit(0);

        // A push triggered from the UI is a "manual" sync; a scheduler/cron run
        // passes ?trigger=scheduler so it is recorded as "automatique" in the history.
        $startedAt = now();
        $triggeredBy = $request->string('trigger')->toString() === SyncLog::TRIGGER_SCHEDULER
            ? SyncLog::TRIGGER_SCHEDULER
            : SyncLog::TRIGGER_MANUAL;

        // The cron commands tag their runs (prices_stock / full_catalog) so the
        // history can tell apart the two scheduled jobs; UI pushes default to full_catalog.
        $syncType = in_array($request->string('sync_type')->toString(), ['prices_stock', 'full_catalog'], true)
            ? $request->string('sync_type')->toString()
            : 'full_catalog';

        $mapping = CategoryMapping::query()
            ->where('tds_category', $code)
            ->where('active', true)
            ->where('ignored', false)
            ->whereNotNull('ps_category_id')
            ->first();

        if (! $mapping) {
            return response()->json([
                'success' => false,
                'message' => 'Cette catégorie n\'est pas mappée à une catégorie PrestaShop.',
            ], 422);
        }

        $psCategoryId = (int) $mapping->ps_category_id;

        $payload = $this->psSettings();

        if (! is_array($payload) || empty($payload['backoffice_url']) || empty($payload['webservice_key'])) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration PrestaShop introuvable ou incomplète.',
            ], 422);
        }

        $shopBase = $this->shopBase($payload['backoffice_url']);

        if (! $shopBase) {
            return response()->json([
                'success' => false,
                'message' => 'URL PrestaShop invalide.',
            ], 422);
        }

        $wsKey = $payload['webservice_key'];

        // Skip products without a price (TD SYNNEX services/warranties priced at 0)
        // so we do not create 0 EUR items in the shop.
        $query = TDSynexProduct::query()
            ->where('category_tds', $code)
            ->where('cost_price', '>', 0);

        $limit = (int) $request->integer('limit', 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            return response()->json([
                'success' => true,
                'ok'      => true,
                'total'   => 0,
                'created' => 0,
                'updated' => 0,
                'errors'  => [],
                'message' => 'Aucun produit local avec un prix pour cette catégorie. Synchronisez d\'abord les produits depuis la page Produits.',
            ]);
        }

        $margin = $mapping->margin_override !== null ? (float) $mapping->margin_override : 15.0;

        // Look up products already in PrestaShop (globally by SKU/reference) so we
        // update (PUT) instead of duplicating (POST) on a re-push.
        $existingRefs = $this->fetchExistingRefs($shopBase, $wsKey, $products->pluck('sku')->all());

        $created = 0;
        $updated = 0;
        $errors  = [];
        $batchSize = 5; // Concurrency cap for writes to the live shop.

        foreach ($products->chunk($batchSize) as $batch) {
            // 1. Create/update the products in this batch concurrently.
            $responses = Http::pool(function (Pool $pool) use ($batch, $shopBase, $wsKey, $psCategoryId, $margin, $existingRefs) {
                foreach ($batch as $product) {
                    $cost = (float) ($product->cost_price ?? 0);
                    $sellPrice = round($cost * (1 + ($margin / 100)), 6);
                    $existingId = $existingRefs[$product->sku] ?? null;

                    $xml = $this->buildProductXml(
                        $product, $psCategoryId, $sellPrice, $product->is_active ? 1 : 0, $existingId
                    );

                    $req = $pool->as((string) $product->sku)
                        ->withBasicAuth($wsKey, '')
                        ->withBody($xml, 'application/xml')
                        ->accept('application/xml')
                        ->timeout(30)
                        ->withoutVerifying();

                    if ($existingId) {
                        $req->put($shopBase . '/api/products/' . $existingId);
                    } else {
                        $req->post($shopBase . '/api/products');
                    }
                }
            });

            // 2. Collect results and the (productId => quantity) map for stock.
            $stockTargets = [];

            foreach ($batch as $product) {
                $existingId = $existingRefs[$product->sku] ?? null;
                $response = $responses[(string) $product->sku] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    $errors[] = [
                        'sku'   => $product->sku,
                        'error' => $response instanceof Response
                            ? $response->status() . ' ' . $this->extractPsError($response->body())
                            : 'Pas de réponse de PrestaShop',
                    ];
                    continue;
                }

                $productId = $this->parseProductId($response->body()) ?? $existingId;

                if ($existingId) {
                    $updated++;
                } else {
                    $created++;
                    if ($productId) {
                        $existingRefs[$product->sku] = $productId; // avoid re-creating if SKU recurs
                    }
                }

                if ($productId) {
                    $stockTargets[(int) $productId] = (int) ($product->stock_qty ?? 0);
                }
            }

            // 3. Update stock for this batch concurrently.
            $this->updateStockBatch($shopBase, $wsKey, $stockTargets);
        }

        $errorsCount = count($errors);
        $successCount = $created + $updated;
        $status = $successCount === 0 && $errorsCount > 0
            ? 'failed'
            : ($errorsCount > 0 ? 'partial' : 'success');

        try {
            Log::info('CategorySync push completed', [
                'category' => $code,
                'ps_category' => $psCategoryId,
                'created' => $created,
                'updated' => $updated,
                'errors'  => $errorsCount,
            ]);
        } catch (\Throwable) {}

        // Record this run in the synchronisation history (sync_logs).
        try {
            $finishedAt = now();
            SyncLog::create([
                'type'              => $syncType,
                'triggered_by'      => $triggeredBy,
                'started_at'        => $startedAt,
                'finished_at'       => $finishedAt,
                'duration_seconds'  => $startedAt->diffInSeconds($finishedAt),
                'status'            => $status,
                'products_created'  => $created,
                'products_updated'  => $updated,
                'products_disabled' => 0,
                'products_skipped'  => (int) TDSynexProduct::query()
                    ->where('category_tds', $code)
                    ->where('cost_price', '<=', 0)
                    ->count(),
                'errors_count'      => $errorsCount,
                'report'            => [
                    'operation'   => 'push_prestashop',
                    'category'    => $code,
                    'ps_category' => $psCategoryId,
                    'margin'      => $margin,
                    'errors'      => array_slice($errors, 0, 100),
                ],
            ]);
        } catch (\Throwable $e) {
            try { Log::error('SyncLog record failed', ['error' => $e->getMessage()]); } catch (\Throwable) {}
        }

        return response()->json([
            'success' => true,
            'ok'      => true,
            'total'   => $successCount,
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ]);
    }

    /**
     * Delete from PrestaShop every product matching the given SKUs (references).
     *
     * Used when a TD SYNNEX category is unmapped ("non mappée"): the products that
     * were previously pushed to the shop must disappear from PrestaShop too. SKUs
     * are resolved to PS product IDs globally by reference, then deleted in small
     * concurrent batches. A 404 is treated as "already gone" (success). Never
     * throws: PrestaShop errors are collected and returned.
     *
     * @param array<int, string> $skus
     * @return array{ok:bool, deleted:int, errors:array<int,array<string,mixed>>, message?:string}
     */
    public function deletePrestashopProductsBySku(array $skus): array
    {
        // Deleting thousands of products is one HTTP round-trip each, so lift limits.
        @set_time_limit(0);

        $skus = array_values(array_unique(array_filter(
            array_map('strval', $skus),
            fn ($s) => trim($s) !== ''
        )));

        if ($skus === []) {
            return ['ok' => true, 'deleted' => 0, 'errors' => []];
        }

        $payload = $this->psSettings();

        if (! is_array($payload) || empty($payload['backoffice_url']) || empty($payload['webservice_key'])) {
            return [
                'ok'      => false,
                'deleted' => 0,
                'errors'  => [],
                'message' => 'Configuration PrestaShop introuvable ou incomplète.',
            ];
        }

        $shopBase = $this->shopBase($payload['backoffice_url']);

        if (! $shopBase) {
            return ['ok' => false, 'deleted' => 0, 'errors' => [], 'message' => 'URL PrestaShop invalide.'];
        }

        $wsKey = $payload['webservice_key'];

        // Resolve SKU => PrestaShop product id (matched globally by reference).
        $existingRefs = $this->fetchExistingRefs($shopBase, $wsKey, $skus);

        if ($existingRefs === []) {
            return ['ok' => true, 'deleted' => 0, 'errors' => []];
        }

        $deleted   = 0;
        $errors    = [];
        $batchSize = 5; // Concurrency cap for writes to the live shop.

        foreach (array_chunk($existingRefs, $batchSize, true) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $shopBase, $wsKey) {
                foreach ($batch as $sku => $productId) {
                    $pool->as((string) $sku)
                        ->withBasicAuth($wsKey, '')
                        ->accept('application/xml')
                        ->timeout(30)
                        ->withoutVerifying()
                        ->delete($shopBase . '/api/products/' . $productId);
                }
            });

            foreach ($batch as $sku => $productId) {
                $response = $responses[(string) $sku] ?? null;

                // PrestaShop returns 200 on a successful delete; a 404 means the
                // product is already absent, which is exactly the end state we want.
                if ($response instanceof Response && ($response->successful() || $response->status() === 404)) {
                    $deleted++;
                    continue;
                }

                $errors[] = [
                    'sku'   => $sku,
                    'id'    => $productId,
                    'error' => $response instanceof Response
                        ? $response->status() . ' ' . $this->extractPsError($response->body())
                        : 'Pas de réponse de PrestaShop',
                ];
            }
        }

        try {
            Log::info('CategorySync delete completed', [
                'requested' => count($skus),
                'resolved'  => count($existingRefs),
                'deleted'   => $deleted,
                'errors'    => count($errors),
            ]);
        } catch (\Throwable) {}

        return ['ok' => empty($errors), 'deleted' => $deleted, 'errors' => $errors];
    }

    // ── PrestaShop helpers ─────────────────────────────────────────────────────

    /**
     * Look up the PrestaShop product IDs for the given SKUs, matched GLOBALLY by
     * reference (not scoped to a category) so a re-push updates the existing
     * product instead of creating a duplicate. Returns ['SKU-123' => 456, ...].
     * SKUs are queried in batches using PrestaShop's OR filter syntax [a|b|c].
     *
     * @param array<int, string> $skus
     */
    private function fetchExistingRefs(string $shopBase, string $wsKey, array $skus): array
    {
        $refs = [];

        $skus = array_values(array_unique(array_filter(array_map('strval', $skus), fn ($s) => $s !== '')));

        foreach (array_chunk($skus, 50) as $chunk) {
            try {
                $resp = $this->psGet($shopBase . '/api/products', $wsKey, [
                    'filter[reference]' => '[' . implode('|', $chunk) . ']',
                    'display'           => '[id,reference]',
                ]);

                if (! $resp->successful()) {
                    continue;
                }

                foreach ($this->parseProductRefs($resp->body()) as $ref => $id) {
                    if (! isset($refs[$ref])) {
                        $refs[$ref] = $id;
                    }
                }
            } catch (\Throwable) {
                // Skip this batch; affected products will be treated as new.
            }
        }

        return $refs;
    }

    /**
     * Update the stock_available records for a batch of products concurrently.
     * Non-blocking: stock failures never abort the push.
     *
     * @param array<int, int> $targets  productId => quantity
     */
    private function updateStockBatch(string $shopBase, string $wsKey, array $targets): void
    {
        if ($targets === []) {
            return;
        }

        $productIds = array_keys($targets);

        try {
            // 1. Resolve the stock_available id for each product, concurrently.
            $lookups = Http::pool(function (Pool $pool) use ($productIds, $shopBase, $wsKey) {
                foreach ($productIds as $productId) {
                    $pool->as((string) $productId)
                        ->withBasicAuth($wsKey, '')
                        ->accept('application/xml')
                        ->timeout(20)
                        ->withoutVerifying()
                        ->get($shopBase . '/api/stock_availables', [
                            'filter[id_product]'           => '[' . $productId . ']',
                            'filter[id_product_attribute]' => '[0]',
                            'display'                      => '[id]',
                        ]);
                }
            });

            $saMap = []; // productId => stockAvailableId
            foreach ($productIds as $productId) {
                $resp = $lookups[(string) $productId] ?? null;
                if ($resp instanceof Response && $resp->successful()) {
                    $saId = $this->parseFirstId($resp->body(), 'stock_available');
                    if ($saId) {
                        $saMap[$productId] = $saId;
                    }
                }
            }

            if ($saMap === []) {
                return;
            }

            // 2. Write the quantities concurrently.
            Http::pool(function (Pool $pool) use ($saMap, $targets, $shopBase, $wsKey) {
                foreach ($saMap as $productId => $saId) {
                    $qty = $targets[$productId];
                    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <stock_available>
    <id><![CDATA[{$saId}]]></id>
    <id_product><![CDATA[{$productId}]]></id_product>
    <id_product_attribute><![CDATA[0]]></id_product_attribute>
    <quantity><![CDATA[{$qty}]]></quantity>
  </stock_available>
</prestashop>
XML;
                    $pool->as((string) $saId)
                        ->withBasicAuth($wsKey, '')
                        ->withBody($xml, 'application/xml')
                        ->accept('application/xml')
                        ->timeout(20)
                        ->withoutVerifying()
                        ->put($shopBase . '/api/stock_availables/' . $saId);
                }
            });
        } catch (\Throwable) {
            // Non-blocking: stock update failure should not abort the sync.
        }
    }

    /**
     * Build the PrestaShop product XML for a create (existingId = null) or update.
     */
    private function buildProductXml(
        TDSynexProduct $p,
        int $psCategoryId,
        float $price,
        int $active,
        ?int $existingId
    ): string {
        $idTag       = $existingId ? '<id><![CDATA[' . $existingId . ']]></id>' : '';
        // PrestaShop limits product name to 128 chars; our names can be far longer
        // (TD SYNNEX puts the full description in the name field).
        $rawName     = $p->name ?: $p->sku;
        $name        = $this->cdata(mb_substr($rawName, 0, 128));
        $description = $this->cdata($p->description ?: '');
        $linkRewrite = $this->cdata($this->slugify(mb_substr($rawName, 0, 100) . ' ' . $p->sku));
        $reference   = $this->cdata($p->sku);
        $ean         = $this->cdata((string) ($p->ean ?: ''));
        $weight      = number_format((float) ($p->weight ?? 0), 3, '.', '');
        $priceStr    = number_format($price, 6, '.', '');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <product>
    {$idTag}
    <id_category_default><![CDATA[{$psCategoryId}]]></id_category_default>
    <id_tax_rules_group><![CDATA[1]]></id_tax_rules_group>
    <reference>{$reference}</reference>
    <ean13>{$ean}</ean13>
    <price><![CDATA[{$priceStr}]]></price>
    <active><![CDATA[{$active}]]></active>
    <state><![CDATA[1]]></state>
    <available_for_order><![CDATA[1]]></available_for_order>
    <show_price><![CDATA[1]]></show_price>
    <weight><![CDATA[{$weight}]]></weight>
    <name>
      <language id="1">{$name}</language>
    </name>
    <description>
      <language id="1">{$description}</language>
    </description>
    <description_short>
      <language id="1"><![CDATA[]]></language>
    </description_short>
    <link_rewrite>
      <language id="1">{$linkRewrite}</language>
    </link_rewrite>
    <associations>
      <categories>
        <category><id><![CDATA[{$psCategoryId}]]></id></category>
      </categories>
    </associations>
  </product>
</prestashop>
XML;
    }

    // ── HTTP helpers ───────────────────────────────────────────────────────────

    /**
     * GET request to PrestaShop API with Basic-auth → ws_key fallback.
     */
    private function psGet(string $url, string $wsKey, array $query = [])
    {
        $resp = Http::withBasicAuth($wsKey, '')
            ->timeout(15)
            ->withoutVerifying()
            ->accept('application/xml')
            ->get($url, $query);

        if ($resp->status() === 401) {
            $resp = Http::timeout(15)
                ->withoutVerifying()
                ->accept('application/xml')
                ->get($url, array_merge($query, ['ws_key' => $wsKey]));
        }

        return $resp;
    }

    /**
     * POST or PUT XML body to PrestaShop API with Basic-auth → ws_key fallback.
     */
    private function psRequest(string $method, string $url, string $wsKey, string $xml)
    {
        $method = strtolower($method);

        $resp = Http::withBasicAuth($wsKey, '')
            ->withBody($xml, 'application/xml')
            ->accept('application/xml')
            ->timeout(20)
            ->withoutVerifying()
            ->{$method}($url);

        if ($resp->status() === 401) {
            $resp = Http::withBody($xml, 'application/xml')
                ->accept('application/xml')
                ->timeout(20)
                ->withoutVerifying()
                ->{$method}($url, ['ws_key' => $wsKey]);
        }

        return $resp;
    }

    // ── XML parsing helpers ────────────────────────────────────────────────────

    /**
     * Parse a map of [reference => id] from a PS product list XML response.
     */
    private function parseProductRefs(string $xmlBody): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);

        if (! $xml) {
            return [];
        }

        $refs  = [];
        $nodes = $xml->xpath('//product') ?: [];

        foreach ($nodes as $node) {
            $id  = (int) ($node->id ?? (string) ($node->attributes()['id'] ?? ''));
            $ref = trim((string) ($node->reference ?? ''));

            if ($id > 0 && $ref !== '') {
                $refs[$ref] = $id;
            }
        }

        return $refs;
    }

    /**
     * Parse the product ID from a PS create/update response.
     */
    private function parseProductId(string $xmlBody): ?int
    {
        return $this->parseFirstId($xmlBody, 'product');
    }

    /**
     * Extract the first <id> value from a named resource element.
     */
    private function parseFirstId(string $xmlBody, string $resource): ?int
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);

        if (! $xml) {
            return null;
        }

        $nodes = $xml->xpath('//' . $resource . '/id') ?: [];

        if (empty($nodes)) {
            $nodes = $xml->xpath('//' . $resource . '[@id]') ?: [];
            if (! empty($nodes)) {
                $id = (int) (string) ($nodes[0]->attributes()['id'] ?? '0');
                return $id > 0 ? $id : null;
            }
        }

        $id = (int) (string) ($nodes[0] ?? '');
        return $id > 0 ? $id : null;
    }

    /**
     * Extract a human-readable error message from a PS XML error response.
     */
    private function extractPsError(string $xmlBody): string
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);

        if (! $xml) {
            return substr(strip_tags($xmlBody), 0, 200);
        }

        $messages = $xml->xpath('//message') ?: [];

        if (! empty($messages)) {
            return trim((string) $messages[0]);
        }

        return substr(strip_tags($xmlBody), 0, 200);
    }

    // ── String / settings helpers ──────────────────────────────────────────────

    private function cdata(string $value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    private function slugify(string $text): string
    {
        // Transliterate to ASCII, lowercase, replace non-alphanum with hyphens
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        $text = substr($text, 0, 128);

        return $text ?: 'product';
    }

    private function psSettings(): ?array
    {
        $path = storage_path('app/integration-settings.json');

        if (! File::exists($path)) {
            return null;
        }

        $contents = json_decode(File::get($path), true);

        return is_array($contents) ? ($contents['prestashop']['payload'] ?? null) : null;
    }

    private function shopBase(string $configuredUrl): ?string
    {
        $configuredUrl = trim($configuredUrl);

        if ($configuredUrl === '') {
            return null;
        }

        $parts = parse_url($configuredUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $basePath = '';

        if (! empty($parts['path'])) {
            $path      = rtrim($parts['path'], '/');
            $adminPos  = stripos($path, '/admin');
            $basePath  = $adminPos !== false ? substr($path, 0, $adminPos) : $path;

            if ($basePath !== '' && $basePath[0] !== '/') {
                $basePath = '/' . $basePath;
            }
        }

        return $parts['scheme'] . '://' . $parts['host'] . $port . $basePath;
    }
}
