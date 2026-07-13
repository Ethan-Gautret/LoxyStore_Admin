<?php

namespace App\Http\Middleware;

use App\Support\CacheStoreResolver;
use App\Support\CacheIndex;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheApiResponses
{
    private const TTL_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->boolean('refresh') || str_contains(strtolower((string) $request->header('Cache-Control', '')), 'no-cache')) {
            $response = $next($request);
            $response->headers->set('X-Api-Cache', 'BYPASS');
            $response->headers->set('X-Api-Cache-Store', CacheStoreResolver::name());

            return $response;
        }

        try {
            $cacheKey = $this->cacheKey($request);
            $cacheStore = Cache::store(CacheStoreResolver::name());

            $cached = $this->supportsTags() ? $cacheStore->tags(['api'])->get($cacheKey) : $cacheStore->get($cacheKey);
            if (is_array($cached) && isset($cached['status'], $cached['body'])) {
                return response()->json(
                    $cached['body'],
                    (int) $cached['status'],
                    $cached['headers'] ?? []
                )
                    ->header('X-Api-Cache', 'HIT')
                    ->header('X-Api-Cache-Store', CacheStoreResolver::name());
            }
        } catch (\Throwable) {
            return $next($request);
        }

        $response = $next($request);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $contentType = $response->headers->get('Content-Type', '');
        if (! str_contains(strtolower($contentType), 'application/json')) {
            return $response;
        }

        $payload = json_decode($response->getContent(), true);
        if (! is_array($payload)) {
            return $response;
        }

        try {
            $cacheStore = Cache::store(CacheStoreResolver::name());
            $cachePayload = [
                'status' => $response->getStatusCode(),
                'body' => $payload,
                'headers' => [],
            ];

            if ($this->supportsTags()) {
                $cacheStore->tags(['api'])->put($cacheKey, $cachePayload, now()->addMinutes(self::TTL_MINUTES));
            } else {
                $cacheStore->put($cacheKey, $cachePayload, now()->addMinutes(self::TTL_MINUTES));
            }

            try {
                // Record the cached key in a lightweight index (fallback when Redis is unavailable)
                CacheIndex::add($cacheKey, [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'cached_at' => now()->toIsoString(),
                    'ttl_minutes' => self::TTL_MINUTES,
                    'store' => CacheStoreResolver::name(),
                ]);
            } catch (\Throwable) {
                // indexing must never break request handling
            }
        } catch (\Throwable) {
        }

        $response->headers->set('X-Api-Cache', 'MISS');
        $response->headers->set('X-Api-Cache-Store', CacheStoreResolver::name());

        return $response;
    }

    private function cacheKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return 'api-response:' . sha1(json_encode([
            $request->method(),
            $request->fullUrl(),
            $userId,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function supportsTags(): bool
    {
        return CacheStoreResolver::name() === 'redis';
    }
}