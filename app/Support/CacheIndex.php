<?php

namespace App\Support;

class CacheIndex
{
    private const INDEX_PATH = 'app/api-cache-index.json';

    public static function path(): string
    {
        return storage_path(self::INDEX_PATH);
    }

    public static function all(): array
    {
        $path = self::path();
        if (! file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    public static function add(string $key, array $meta = []): void
    {
        $items = self::all();
        $items[$key] = array_merge($items[$key] ?? [], $meta);
        $items[$key]['key'] = $key;
        $items[$key]['updated_at'] = now()->toIsoString();

        // write atomically
        $tmp = self::path() . '.tmp';
        @file_put_contents($tmp, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($tmp, self::path());
    }

    public static function remove(string $key): void
    {
        $items = self::all();
        if (isset($items[$key])) {
            unset($items[$key]);
            $tmp = self::path() . '.tmp';
            @file_put_contents($tmp, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @rename($tmp, self::path());
        }
    }
}
