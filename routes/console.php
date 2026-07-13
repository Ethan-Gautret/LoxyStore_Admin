<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use App\Support\CacheStoreResolver;
use App\Support\CacheIndex;

/**
 * List API cache entries. Uses Redis when available, otherwise reads the local index file.
 */
Artisan::command('cache:list-api', function () {
    $this->comment('Listing API cache entries:');

    $printEntry = function (string $key, array $meta = []) {
        $this->line('- ' . $key);

        if (! empty($meta['url'])) {
            $this->line('  url: ' . $meta['url']);
        }

        if (! empty($meta['method'])) {
            $this->line('  method: ' . $meta['method']);
        }

        if (! empty($meta['store'])) {
            $this->line('  store: ' . $meta['store']);
        }

        if (! empty($meta['cached_at'])) {
            $this->line('  cached_at: ' . $meta['cached_at']);
        }

        if (! empty($meta['ttl_minutes'])) {
            $this->line('  ttl: ' . $meta['ttl_minutes'] . 'm');
        }

        if (array_key_exists('expires_at', $meta) && ! empty($meta['expires_at'])) {
            $this->line('  expires_at: ' . $meta['expires_at']);
        }

        if (array_key_exists('items', $meta) && is_numeric($meta['items'])) {
            $this->line('  items: ' . $meta['items']);
        }

        if (array_key_exists('status', $meta) && is_numeric($meta['status'])) {
            $this->line('  status: ' . $meta['status']);
        }

        if (! empty($meta['body_preview'])) {
            $this->line('  body_preview: ' . $meta['body_preview']);
        }

        if (! empty($meta['details'])) {
            foreach ($meta['details'] as $label => $value) {
                $this->line('  ' . $label . ': ' . $value);
            }
        }
    };

    if (CacheStoreResolver::name() === 'redis') {
        try {
            $store = Cache::store('redis');
            if (! method_exists($store, 'getRedis')) {
                throw new \RuntimeException('Redis store does not expose getRedis()');
            }

            $redis = $store->getRedis();

            $iterator = null;
            $count = 0;
            do {
                $keys = $redis->scan($iterator, 'api-response:*', 100);
                if ($keys === false) {
                    break;
                }
                foreach ($keys as $k) {
                    $ttl = $redis->ttl($k);
                    $value = $store->get($k);
                    $body = is_array($value) && isset($value['body']) && is_array($value['body']) ? $value['body'] : null;
                    $count = is_array($body) && isset($body['data']) && is_array($body['data']) ? count($body['data']) : null;

                    $meta = [
                        'store' => 'redis',
                        'ttl_minutes' => $ttl >= 0 ? round($ttl / 60, 2) : null,
                        'expires_at' => $ttl >= 0 ? now()->addSeconds($ttl)->toIso8601String() : 'no-expiry',
                    ];

                    if (is_array($value)) {
                        $meta['status'] = $value['status'] ?? null;
                        $meta['body_preview'] = $count !== null ? 'data items: ' . $count : null;
                    }

                    $printEntry($k, $meta);
                    $count++;
                }
            } while ($iterator > 0);

            if ($count === 0) {
                $this->info('No keys found matching api-response:*');
            } else {
                $this->info("Found {$count} keys.");
            }

            return 0;
        } catch (\Throwable $e) {
            $this->comment('Redis scan failed, falling back to index file: ' . $e->getMessage());
        }
    }

    $items = CacheIndex::all();
    if (empty($items)) {
        $this->info('No cached items indexed.');
        return 0;
    }

    foreach ($items as $key => $meta) {
        $printEntry($key, $meta);
    }

    $this->info('Indexed entries: ' . count($items));
    return 0;
})->purpose('List API cache entries (Redis or index file)');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
