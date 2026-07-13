<?php

namespace App\Support;

class CacheStoreResolver
{
    public static function name(): string
    {
        if (class_exists(\Redis::class) && self::redisServerIsReachable()) {
            return 'redis';
        }

        if (class_exists(\Predis\Client::class) && self::redisServerIsReachable()) {
            config(['database.redis.client' => 'predis']);

            return 'redis';
        }

        return 'file';
    }

    private static function redisServerIsReachable(): bool
    {
        $host = (string) env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        if ($host === '') {
            return false;
        }

        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 0.1);

        if (! is_resource($connection)) {
            return false;
        }

        fclose($connection);

        return true;
    }
}