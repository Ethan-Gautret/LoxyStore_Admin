<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Reads / writes the scheduled-sync configuration in storage/app/cron-settings.json
 * (same JSON-file convention as integration-settings.json).
 *
 * Two scheduled jobs are configured:
 *   - prices_stock : re-push mapped categories to refresh price & stock (light, frequent)
 *   - full_catalog : import the TD SYNNEX catalogue then push (heavy, daily)
 */
class CronSettings
{
    /** Job keys that map to the `sync:run` command + the sync_logs.type enum. */
    public const JOBS = ['prices_stock', 'full_catalog'];

    public static function defaults(): array
    {
        return [
            'prices_stock' => [
                'label'       => 'Sync Prix & Stock',
                'description' => 'Mise à jour rapide des prix et stocks uniquement',
                'active'      => false,
                'frequency'   => 'every_2h',
                'cron'        => '0 */2 * * *',
            ],
            'full_catalog' => [
                'label'       => 'Sync Catalogue Complet',
                'description' => 'Import nouveaux produits, descriptions, images',
                'active'      => false,
                'frequency'   => 'daily',
                'cron'        => '0 2 * * *',
            ],
            'advanced' => [
                'batch_size'      => 200,
                'batch_delay_ms'  => 1000,
                'redis_workers'   => 4,
                'job_timeout'     => 3600,
                'retry'           => true,
                'retry_attempts'  => 3,
                'notify_on_error' => true,
                'notify_email'    => 'admin@loxystore.com',
            ],
        ];
    }

    /** The full config, defaults merged with whatever is persisted. */
    public static function all(): array
    {
        $stored = self::read();
        $defaults = self::defaults();

        $merged = $defaults;
        foreach ($defaults as $key => $section) {
            if (is_array($section) && isset($stored[$key]) && is_array($stored[$key])) {
                $merged[$key] = array_merge($section, $stored[$key]);
            }
        }

        return $merged;
    }

    public static function job(string $key): ?array
    {
        if (! in_array($key, self::JOBS, true)) {
            return null;
        }

        return self::all()[$key] ?? null;
    }

    public static function isActive(string $key): bool
    {
        return (bool) (self::job($key)['active'] ?? false);
    }

    /**
     * Merge a partial update into the stored config and persist it.
     * Only known job/advanced keys are kept.
     */
    public static function update(array $patch): array
    {
        $current = self::all();

        foreach (self::JOBS as $key) {
            if (isset($patch[$key]) && is_array($patch[$key])) {
                $current[$key] = array_merge($current[$key], array_intersect_key($patch[$key], array_flip([
                    'active', 'frequency', 'cron', 'label', 'description',
                ])));
            }
        }

        if (isset($patch['advanced']) && is_array($patch['advanced'])) {
            $current['advanced'] = array_merge($current['advanced'], $patch['advanced']);
        }

        self::write($current);

        return $current;
    }

    private static function read(): array
    {
        $path = self::path();

        if (! File::exists($path)) {
            return [];
        }

        $contents = json_decode(File::get($path), true);

        return is_array($contents) ? $contents : [];
    }

    private static function write(array $settings): void
    {
        File::put(self::path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function path(): string
    {
        return storage_path('app/cron-settings.json');
    }
}
