<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\TDSynexProduct;
use App\Support\CacheStoreResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http as HttpClient;

class BrandController extends Controller
{
    /**
     * Get all brands with product counts
     */
    public function index(Request $request)
    {
        $query = Brand::query();

        // Filter by blacklist status
        if ($request->has('blacklisted')) {
            $blacklisted = $request->boolean('blacklisted');
            $query->where('blacklisted', $blacklisted);
        }

        // Filter by active status
        if ($request->has('active')) {
            $active = $request->boolean('active');
            $query->where('active', $active);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->string('search');
            $query->where('tds_manufacturer', 'like', "%{$search}%");
        }

        // Sort
        $sortBy = $request->string('sort', 'tds_manufacturer');
        $sortOrder = $request->string('order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $brands = $query->get();

        // If no local brands and a search term is provided, attempt to fetch manufacturers
        // from the remote TDSynex catalogue to provide live suggestions.
        if ($brands->isEmpty() && $request->filled('search')) {
            try {
                $remote = $this->fetchManufacturersFromTdsynex($request->string('search'));

                if (! empty($remote)) {
                    return response()->json([
                        'data' => array_map(function ($name, $i) {
                            return [
                                'id' => 'remote_' . $i,
                                'tds_manufacturer' => $name,
                                'ps_manufacturer_id' => null,
                                'product_count' => null,
                                'active' => true,
                                'blacklisted' => false,
                                'blacklist_reason' => null,
                            ];
                        }, $remote, array_keys($remote)),
                        'count' => count($remote),
                        'remote_source' => true,
                    ]);
                }
            } catch (\Exception $e) {
                // ignore remote failures and fall back to empty local response
            }
        }

        // Add product count and map data
        return response()->json([
            'data' => $brands->map(function (Brand $brand) {
                $productCount = TDSynexProduct::where('manufacturer', $brand->tds_manufacturer)->count();
                
                return [
                    'id' => $brand->id,
                    'tds_manufacturer' => $brand->tds_manufacturer,
                    'ps_manufacturer_id' => $brand->ps_manufacturer_id,
                    'product_count' => $productCount,
                    'active' => $brand->active,
                    'blacklisted' => $brand->blacklisted,
                    'blacklist_reason' => $brand->blacklist_reason,
                    'created_at' => $brand->created_at,
                    'updated_at' => $brand->updated_at,
                ];
            }),
            'count' => $brands->count(),
        ]);
    }

    /**
     * Get a single brand
     */
    public function show(Brand $brand)
    {
        $productCount = TDSynexProduct::where('manufacturer', $brand->tds_manufacturer)->count();
        
        return response()->json([
            'id' => $brand->id,
            'tds_manufacturer' => $brand->tds_manufacturer,
            'ps_manufacturer_id' => $brand->ps_manufacturer_id,
            'product_count' => $productCount,
            'active' => $brand->active,
            'blacklisted' => $brand->blacklisted,
            'blacklist_reason' => $brand->blacklist_reason,
            'created_at' => $brand->created_at,
            'updated_at' => $brand->updated_at,
        ]);
    }

    /**
     * Create a new brand
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tds_manufacturer' => 'required|string|max:150|unique:brand_mappings,tds_manufacturer',
            'ps_manufacturer_id' => 'nullable|integer',
            'active' => 'boolean',
            'blacklisted' => 'boolean',
            'blacklist_reason' => 'nullable|string|max:255',
        ]);

        $brand = Brand::create($validated);

        try {
            if (CacheStoreResolver::name() === 'redis') {
                Cache::store(CacheStoreResolver::name())->tags(['api'])->flush();
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'message' => 'Brand created successfully',
            'data' => $brand,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update a brand
     */
    public function update(Request $request, Brand $brand)
    {
        $validated = $request->validate([
            'ps_manufacturer_id' => 'nullable|integer',
            'active' => 'boolean',
            'blacklisted' => 'boolean',
            'blacklist_reason' => 'nullable|string|max:255',
        ]);

        $brand->update($validated);

        try {
            if (CacheStoreResolver::name() === 'redis') {
                Cache::store(CacheStoreResolver::name())->tags(['api'])->flush();
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'message' => 'Brand updated successfully',
            'data' => $brand,
        ]);
    }

    /**
     * Toggle blacklist status
     */
    public function toggleBlacklist(Request $request, Brand $brand)
    {
        $validated = $request->validate([
            'blacklist_reason' => 'nullable|string|max:255',
        ]);

        $brand->update([
            'blacklisted' => ! $brand->blacklisted,
            'blacklist_reason' => $validated['blacklist_reason'] ?? null,
        ]);

        try {
            if (CacheStoreResolver::name() === 'redis') {
                Cache::store(CacheStoreResolver::name())->tags(['api'])->flush();
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'message' => 'Blacklist status updated',
            'data' => $brand,
        ]);
    }

    /**
     * Sync brands from TDSynex products
     */
    public function sync(Request $request)
    {
        try {
            $manufacturers = $this->extractManufacturersFromProducts();

            // If no manufacturers found locally, attempt a remote import from TDSynex
            $remoteImported = false;
            if (empty($manufacturers)) {
                try {
                    $manufacturers = $this->fetchManufacturersFromTdsynex(null); // null => full import
                    $remoteImported = ! empty($manufacturers);
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'created' => 0,
                        'updated' => 0,
                        'total' => 0,
                        'source_products' => TDSynexProduct::count(),
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            if (empty($manufacturers)) {
                return response()->json([
                    'message' => 'Aucun fabricant trouvé: la table des produits TDSynex est vide et l\'import distant n\'a retourné aucun fabricant.',
                    'created' => 0,
                    'updated' => 0,
                    'total' => 0,
                    'source_products' => TDSynexProduct::count(),
                    'remote_attempt' => $remoteImported,
                ], Response::HTTP_OK);
            }

            $created = 0;
            $updated = 0;

            foreach ($manufacturers as $manufacturer) {
                $brand = Brand::firstOrCreate(
                    ['tds_manufacturer' => $manufacturer],
                    [
                        'active' => true,
                        'blacklisted' => false,
                    ]
                );


            try {
                if (CacheStoreResolver::name() === 'redis') {
                    Cache::store(CacheStoreResolver::name())->tags(['api'])->flush();
                }
            } catch (\Throwable) {
            }
                if ($brand->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return response()->json([
                'message' => 'Synchronisation des marques terminée.',
                'created' => $created,
                'updated' => $updated,
                'total' => $created + $updated,
                'source_products' => TDSynexProduct::count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract manufacturers from direct columns and raw payload fallbacks.
     */
    private function extractManufacturersFromProducts(): array
    {
        $products = TDSynexProduct::query()
            ->select(['manufacturer', 'raw_payload'])
            ->where(function ($query) {
                $query->whereNotNull('manufacturer')
                    ->orWhereNotNull('raw_payload');
            })
            ->get();

        $manufacturers = [];

        foreach ($products as $product) {
            foreach ($this->extractManufacturerCandidates($product->manufacturer) as $candidate) {
                $manufacturers[] = $candidate;
            }

            if (is_array($product->raw_payload)) {
                foreach ($this->extractManufacturerCandidates($this->readManufacturerFromPayload($product->raw_payload)) as $candidate) {
                    $manufacturers[] = $candidate;
                }
            }
        }

        $manufacturers = array_values(array_unique(array_filter(array_map(
            fn ($name) => $this->normalizeManufacturerName($name),
            $manufacturers
        ))));

        sort($manufacturers, SORT_NATURAL | SORT_FLAG_CASE);

        return $manufacturers;
    }

    /**
     * Try several common keys found in raw payloads.
     */
    private function readManufacturerFromPayload(array $payload): string|array|null
    {
        $keys = [
            'manufacturer',
            'manufacturer_name',
            'manufacturerName',
            'brand',
            'brand_name',
            'vendor',
            'supplier',
        ];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value) || is_array($value)) {
                return $value;
            }
        }

        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) || is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Flatten a value that may be a string or nested array into candidate names.
     */
    private function extractManufacturerCandidates(string|array|null $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $candidates = [];

        foreach (['name', 'label', 'value', 'title', 'brand'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $candidates[] = $value[$key];
            }
        }

        foreach ($value as $nestedValue) {
            if (is_string($nestedValue)) {
                $candidates[] = $nestedValue;
            }
        }

        return $candidates;
    }

    /**
     * Normalize names before deduplication.
     */
    private function normalizeManufacturerName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name;
    }

    /**
     * Return a live list of manufacturers directly from the TDSynex API (no local DB storage).
     */
    public function tdsynnexManufacturers(Request $request)
    {
        try {
            $search = $request->filled('search') ? (string) $request->string('search') : null;
            $manufacturers = $this->fetchManufacturersFromTdsynex($search);

            return response()->json([
                'data'  => $manufacturers,
                'count' => count($manufacturers),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data'    => [],
                'count'   => 0,
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Diagnostic endpoint: tests token + one catalogue request and returns raw details.
     */
    public function tdsynnexTest()
    {
        $storePath = storage_path('app/integration-settings.json');
        if (! File::exists($storePath)) {
            return response()->json(['ok' => false, 'step' => 'settings', 'message' => 'Fichier integration-settings.json introuvable.']);
        }

        $config  = json_decode(File::get($storePath), true) ?? [];
        $payload = $config['tdsynnex']['payload'] ?? null;
        if (! is_array($payload) || empty($payload['endpoint_url'])) {
            return response()->json(['ok' => false, 'step' => 'settings', 'message' => 'endpoint_url manquant dans les paramètres.']);
        }

        // Step 1: token
        $tokenResp = HttpClient::timeout(15)
            ->withoutVerifying()
            ->asForm()
            ->post($payload['endpoint_url'], [
                'grant_type'    => 'client_credentials',
                'client_id'     => $payload['client_id'] ?? '',
                'client_secret' => $payload['client_secret'] ?? '',
            ]);

        if (! $tokenResp->ok()) {
            return response()->json([
                'ok'      => false,
                'step'    => 'token',
                'status'  => $tokenResp->status(),
                'message' => 'Échec de l\'authentification TDSynex.',
                'body'    => $tokenResp->body(),
            ]);
        }

        $token = $tokenResp->json()['access_token'] ?? null;
        if (! $token) {
            return response()->json([
                'ok'      => false,
                'step'    => 'token',
                'message' => 'Token absent dans la réponse.',
                'body'    => $tokenResp->json(),
            ]);
        }

        // Step 2: catalogue (v1)
        $parsed       = parse_url($payload['endpoint_url']);
        $catalogueUrl = sprintf('%s://%s/%s/resellers/v1/products/catalogue',
            $parsed['scheme'] ?? 'https',
            $parsed['host']   ?? '',
            $payload['region'] ?? 'eu'
        );

        $catResp = HttpClient::timeout(10)
            ->withToken($token)
            ->withoutVerifying()
            ->acceptJson()
            ->asJson()
            ->post($catalogueUrl, ['class' => 'GENERAL', 'page' => 1, 'pageSize' => 5, 'includePrice' => false, 'includeStock' => false]);

        return response()->json([
            'ok'            => $catResp->ok(),
            'step'          => 'catalogue',
            'token_ok'      => true,
            'catalogue_url' => $catalogueUrl,
            'status'        => $catResp->status(),
            'body'          => $catResp->json() ?? $catResp->body(),
        ]);
    }

    /**
     * Fetch manufacturers from the remote TDSynex catalogue using the stored integration settings.
     *
     * Uses sequential HTTP requests (one per key class) with explicit error surfacing.
     * For a search term the `manufacturer` field is used to filter.
     */
    private function fetchManufacturersFromTdsynex(?string $search = null): array
    {
        $storePath = storage_path('app/integration-settings.json');
        if (! File::exists($storePath)) {
            return [];
        }

        $config  = json_decode(File::get($storePath), true) ?? [];
        $payload = $config['tdsynnex']['payload'] ?? null;
        if (! is_array($payload) || empty($payload['endpoint_url'])) {
            return [];
        }

        $token = $this->getTdsynexAccessToken($payload);
        if (! $token) {
            throw new \RuntimeException('Impossible d\'obtenir un token TDSynex. Vérifiez vos identifiants dans les paramètres d\'intégration.');
        }

        $parsed = parse_url($payload['endpoint_url']);
        $host   = $parsed['host'] ?? null;
        if (! $host) {
            return [];
        }

        $catalogueUrl = sprintf('%s://%s/%s/resellers/v1/products/catalogue',
            $parsed['scheme'] ?? 'https',
            $host,
            $payload['region'] ?? 'eu'
        );

        $manufacturers = Cache::store(\App\Support\CacheStoreResolver::name())->remember(
            $this->tdsynexManufacturersCacheKey($payload, $search),
            $search ? now()->addMinutes(10) : now()->addMinutes(20),
            function () use ($token, $catalogueUrl, $search) {
                $manufacturers = [];
                $baseBody = ['page' => 1, 'pageSize' => 100, 'includePrice' => false, 'includeStock' => false];

                if ($search) {
                    $resp = HttpClient::timeout(15)
                        ->withToken($token)
                        ->withoutVerifying()
                        ->acceptJson()
                        ->asJson()
                        ->post($catalogueUrl, array_merge($baseBody, ['manufacturer' => $search]));

                    if (! $resp->ok()) {
                        throw new \RuntimeException(sprintf(
                            'TDSynex API %d : %s', $resp->status(), trim($resp->body())
                        ));
                    }

                    foreach ($resp->json()['products'] ?? [] as $p) {
                        if (! empty($p['manufacturer']) && stripos($p['manufacturer'], $search) !== false) {
                            $manufacturers[] = $this->normalizeManufacturerName($p['manufacturer']);
                        }
                    }
                } else {
                    $keyClasses = [
                        'COMPORT', 'COMDESK', 'COMSER', 'COMHAND',
                        'PERMONIT', 'KEYBMICE', 'PERSTOR', 'TELMOBILE',
                        'NWLAN', 'NWWIRELSS', 'SOFTSEC', 'MULTIFU',
                        'PRISFD', 'AVUCC', 'GENERAL',
                    ];

                    foreach ($keyClasses as $class) {
                        try {
                            $resp = HttpClient::timeout(10)
                                ->withToken($token)
                                ->withoutVerifying()
                                ->acceptJson()
                                ->asJson()
                                ->post($catalogueUrl, array_merge($baseBody, ['class' => $class]));

                            if (! $resp->ok()) {
                                continue;
                            }

                            foreach ($resp->json()['products'] ?? [] as $p) {
                                if (! empty($p['manufacturer'])) {
                                    $manufacturers[] = $this->normalizeManufacturerName($p['manufacturer']);
                                }
                            }
                        } catch (\RuntimeException $e) {
                            throw $e;
                        } catch (\Exception) {
                        }
                    }
                }

                $manufacturers = array_values(array_unique(array_filter($manufacturers)));
                sort($manufacturers, SORT_NATURAL | SORT_FLAG_CASE);

                return $manufacturers;
            }
        );

        return $manufacturers;
    }

    /**
     * Request an access token from TDSynex using client credentials.
     */
    private function getTdsynexAccessToken(array $payload): ?string
    {
        if (empty($payload['endpoint_url']) || empty($payload['client_id']) || empty($payload['client_secret'])) {
            return null;
        }

        return Cache::store(\App\Support\CacheStoreResolver::name())->remember(
            $this->tdsynexTokenCacheKey($payload),
            now()->addMinutes(50),
            function () use ($payload) {
                try {
                    $resp = HttpClient::timeout(20)
                        ->withoutVerifying()
                        ->asForm()
                        ->post($payload['endpoint_url'], [
                            'grant_type' => 'client_credentials',
                            'client_id' => $payload['client_id'],
                            'client_secret' => $payload['client_secret'],
                        ]);

                    if (! $resp->ok()) {
                        return null;
                    }

                    $data = $resp->json();

                    return $data['access_token'] ?? null;
                } catch (\Exception) {
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

    private function tdsynexManufacturersCacheKey(array $payload, ?string $search): string
    {
        return 'integration:tdsynnex:manufacturers:' . sha1(json_encode([
            $payload['endpoint_url'] ?? '',
            $payload['client_id'] ?? '',
            $payload['region'] ?? '',
            $search ? mb_strtolower(trim($search)) : null,
        ], JSON_UNESCAPED_SLASHES));
    }
}
