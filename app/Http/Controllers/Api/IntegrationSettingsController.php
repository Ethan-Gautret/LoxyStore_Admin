<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CacheStoreResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IntegrationSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'tdsynnex' => $this->settingsFor('tdsynnex'),
            'prestashop' => $this->settingsFor('prestashop'),
        ]);
    }

    public function update(Request $request, string $section): JsonResponse
    {
        $payload = $this->validatedPayload($request, $section);

        $settings = $this->readStore();
        $settings[$section] = [
            'payload' => $payload,
            'updated_at' => now()->toIso8601String(),
        ];

        $this->writeStore($settings);
        try {
            if (CacheStoreResolver::name() === 'redis') {
                Cache::store(CacheStoreResolver::name())->tags(['api'])->flush();
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'message' => 'Configuration enregistrée.',
            'section' => $section,
            'payload' => $payload,
            'updated_at' => $settings[$section]['updated_at'],
        ]);
    }

    public function test(Request $request, string $section): JsonResponse
    {
        $payload = $this->validatedPayload($request, $section);

        if ($section === 'tdsynnex') {
            return $this->testTDSynexConnection($payload);
        }

        return $this->testPrestaShopConnection($payload);
    }

    public function prestashopCategories(): JsonResponse
    {
        $payload = $this->settingsFor('prestashop');

        if (empty($payload['backoffice_url']) || empty($payload['webservice_key'])) {
            return response()->json([
                'success' => false,
                'message' => 'Configurez l\'URL du back-office et la clé Webservice avant de charger les catégories.',
            ], 422);
        }

        $shopBaseUrl = $this->prestashopShopBaseUrl($payload['backoffice_url']);

        if ($shopBaseUrl === null) {
            return response()->json([
                'success' => false,
                'message' => 'L\'URL PrestaShop est invalide. Utilisez une URL complète comme https://votre-boutique.tld/admin... ou https://votre-boutique.tld.',
            ], 422);
        }

        try {
            $cacheKey = $this->prestashopCategoriesCacheKey($payload, $shopBaseUrl);
            $categories = Cache::store(CacheStoreResolver::name())->remember($cacheKey, now()->addMinutes(30), function () use ($payload, $shopBaseUrl) {
                $response = $this->performPrestashopGet($shopBaseUrl . '/api/categories', $payload['webservice_key'], [
                    'display' => '[id,id_parent,name,active,level_depth]',
                ]);

                if (! $response->successful()) {
                    if ($response->status() === 401) {
                        throw new \RuntimeException('Impossible de charger les catégories PrestaShop: clé Webservice invalide ou droits insuffisants sur categories.');
                    }

                    throw new \RuntimeException('Impossible de charger les catégories PrestaShop: ' . $response->status() . ' ' . $response->reason());
                }

                return $this->parsePrestashopCategories($response->body());
            });

            return response()->json([
                'success' => true,
                'message' => 'Catégories PrestaShop chargées.',
                'categories' => $categories,
                'tree' => $this->buildCategoryTree($categories),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion: ' . $e->getMessage(),
            ], 400);
        }
    }

    private function testTDSynexConnection(array $payload): JsonResponse
    {
        try {
            $response = Http::timeout(10)
                ->withoutVerifying()
                ->asForm()
                ->post($payload['endpoint_url'], [
                    'grant_type' => 'client_credentials',
                    'client_id' => $payload['client_id'],
                    'client_secret' => $payload['client_secret'],
                ]);

            if ($response->successful() && $response->json('access_token')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connexion à TDSynex réussie ✓',
                    'section' => 'tdsynnex',
                    'status' => $response->status(),
                ]);
            }

            if (in_array($response->status(), [401, 403], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Connexion échouée: identifiants invalides.',
                    'section' => 'tdsynnex',
                    'status' => $response->status(),
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Connexion échouée: ' . $response->status() . ' ' . $response->reason(),
                'section' => 'tdsynnex',
                'status' => $response->status(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion: ' . $e->getMessage(),
                'section' => 'tdsynnex',
            ], 400);
        }
    }

    private function testPrestaShopConnection(array $payload): JsonResponse
    {
        $shopBaseUrl = $this->prestashopShopBaseUrl($payload['backoffice_url']);

        if ($shopBaseUrl === null) {
            return response()->json([
                'success' => false,
                'message' => 'L\'URL PrestaShop est invalide. Utilisez une URL complète comme https://votre-boutique.tld/admin... ou https://votre-boutique.tld.',
                'section' => 'prestashop',
            ], 400);
        }

        try {
            $response = $this->performPrestashopGet($shopBaseUrl . '/api', $payload['webservice_key']);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connexion à PrestaShop réussie ✓',
                    'section' => 'prestashop',
                ]);
            }

            if ($response->status() === 401) {
                return response()->json([
                    'success' => false,
                    'message' => 'Connexion échouée: clé Webservice invalide ou droits insuffisants sur la ressource categories.',
                    'section' => 'prestashop',
                    'status' => 401,
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Connexion échouée: ' . $response->status() . ' ' . $response->reason(),
                'section' => 'prestashop',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion: ' . $e->getMessage(),
                'section' => 'prestashop',
            ], 400);
        }
    }

    private function parsePrestashopCategories(string $xmlBody): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);

        if (! $xml) {
            return [];
        }

        $categories = [];
        $categoryNodes = $xml->xpath('//category') ?: [];

        foreach ($categoryNodes as $node) {
            $id = (int) ($node->id ?? 0);

            if ($id <= 0) {
                continue;
            }

            $categories[] = [
                'id' => $id,
                'parent_id' => (int) ($node->id_parent ?? 0),
                'name' => $this->extractPrestashopCategoryName($node),
                'active' => (string) ($node->active ?? '1') === '1',
                'level_depth' => (int) ($node->level_depth ?? 0),
            ];
        }

        return $categories;
    }

    private function extractPrestashopCategoryName(\SimpleXMLElement $node): string
    {
        if (isset($node->name)) {
            $languages = $node->name->children();

            if ($languages->count() > 0) {
                $language = $languages[0];
                $value = trim((string) $language);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return trim((string) ($node->name ?? ''));
    }

    private function prestashopShopBaseUrl(string $configuredUrl): ?string
    {
        $configuredUrl = trim($configuredUrl);

        if ($configuredUrl === '') {
            return null;
        }

        $parts = parse_url($configuredUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        // Preserve a possible base path (e.g. /shop/) but strip any admin folder
        // If user provided an admin URL like https://example.tld/admin123, we want
        // the shop base to be https://example.tld or https://example.tld/path (if installed in a subfolder).
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        $basePath = '';

        if (! empty($parts['path'])) {
            // Normalize trailing slash
            $path = rtrim($parts['path'], '/');

            // If path contains an admin folder (starts with /admin), strip that part
            $adminPos = stripos($path, '/admin');

            if ($adminPos !== false) {
                $basePath = substr($path, 0, $adminPos);
            } else {
                // Keep full path when Prestashop is installed in a subfolder (not an admin URL)
                $basePath = $path;
            }

            // Ensure leading slash if we have a base path
            if ($basePath !== '' && $basePath[0] !== '/') {
                $basePath = '/' . $basePath;
            }
        }

        return $parts['scheme'] . '://' . $parts['host'] . $port . $basePath;
    }

    /**
     * Perform a GET request to a PrestaShop API endpoint.
     * First attempt uses HTTP Basic auth (key as username). If that returns 401,
     * attempt again by supplying the `ws_key` query parameter (some servers
     * block Authorization headers).
     *
     * Returns the Http client response object.
     */
    private function performPrestashopGet(string $url, string $key, array $query = [])
    {
        // Attempt 1: Basic Auth
        $response = Http::withBasicAuth($key, '')
            ->timeout(15)
            ->withoutVerifying()
            ->accept('application/xml')
            ->get($url, $query);

        if ($response->status() !== 401) {
            if (! $response->successful()) {
                // Log failures for debugging (do not log the key itself)
                Log::warning('PrestaShop API request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body_snippet' => substr($response->body(), 0, 1000),
                ]);
            }

            return $response;
        }

        // Attempt 2: fallback to ws_key query param
        $queryWithKey = array_merge($query, ['ws_key' => $key]);

        $fallback = Http::timeout(15)
            ->withoutVerifying()
            ->accept('application/xml')
            ->get($url, $queryWithKey);

        if (! $fallback->successful()) {
            Log::warning('PrestaShop API fallback with ws_key failed', [
                'url' => $url,
                'status' => $fallback->status(),
                'body_snippet' => substr($fallback->body(), 0, 1000),
            ]);
        }

        return $fallback;
    }

    private function prestashopCategoriesCacheKey(array $payload, string $shopBaseUrl): string
    {
        return 'integration:prestashop:categories:' . sha1($shopBaseUrl . '|' . $payload['webservice_key'] . '|' . json_encode([
            'display' => '[id,id_parent,name,active,level_depth]',
        ], JSON_UNESCAPED_SLASHES));
    }

    private function buildCategoryTree(array $categories, ?int $parentId = null): array
    {
        $children = [];

        $rootPredicate = function (array $category): bool {
            $parentId = (int) ($category['parent_id'] ?? 0);
            $id = (int) ($category['id'] ?? 0);

            return $parentId === 0 || $parentId === 1 || $parentId === $id;
        };

        foreach ($categories as $category) {
            $matchesParent = $parentId === null
                ? $rootPredicate($category)
                : (int) ($category['parent_id'] ?? 0) === $parentId;

            if (! $matchesParent) {
                continue;
            }

            $children[] = [
                'id' => $category['id'],
                'parent_id' => $category['parent_id'],
                'name' => $category['name'],
                'active' => $category['active'],
                'level_depth' => $category['level_depth'],
                'children' => $this->buildCategoryTree($categories, (int) $category['id']),
            ];
        }

        return $children;
    }

    private function settingsFor(string $section): array
    {
        $settings = $this->readStore();

        return $settings[$section]['payload'] ?? $this->defaultPayload($section);
    }

    private function defaultPayload(string $section): array
    {
        return match ($section) {
            'tdsynnex' => [
                'endpoint_url' => 'https://api.tdsynnex.com/eu/auth/token',
                'region' => 'eu',
                'client_id' => '',
                'client_secret' => '',
                'rate_limit' => 10,
            ],
            'prestashop' => [
                'backoffice_url' => '',
                'table_prefix' => 'ps_',
                'webservice_key' => '',
                'write_mode' => 'webservice',
            ],
            default => [],
        };
    }

    private function validatedPayload(Request $request, string $section): array
    {
        return $request->validate($this->rulesFor($section));
    }

    private function rulesFor(string $section): array
    {
        return match ($section) {
            'tdsynnex' => [
                'endpoint_url' => ['required', 'url', 'max:2048'],
                'region' => ['required', Rule::in(['eu', 'na'])],
                'client_id' => ['required', 'string', 'max:255'],
                'client_secret' => ['required', 'string', 'max:255'],
                'rate_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            ],
            'prestashop' => [
                'backoffice_url' => ['required', 'url', 'max:2048'],
                'table_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/'],
                'webservice_key' => ['required', 'string', 'max:255'],
                'write_mode' => ['required', Rule::in(['webservice', 'sql'])],
            ],
            default => abort(404, 'Section inconnue.'),
        };
    }

    private function readStore(): array
    {
        $path = $this->storePath();

        if (! File::exists($path)) {
            return [];
        }

        $contents = json_decode(File::get($path), true);

        return is_array($contents) ? $contents : [];
    }

    private function writeStore(array $settings): void
    {
        File::put(
            $this->storePath(),
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function storePath(): string
    {
        return storage_path('app/integration-settings.json');
    }
}