<?php

namespace App\Oracly\Support;

use Illuminate\Support\Facades\Cache;

final class OraclyCache
{
    public const TTL_SECONDS = 120;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $key, callable $callback, int $ttl = self::TTL_SECONDS): mixed
    {
        return Cache::remember('oracly:'.$key, $ttl, $callback);
    }

    public static function forgetPrefix(string $prefix = ''): void
    {
        // file/database stores don't support wildcard flush — bump generation key.
        $generation = (int) Cache::get('oracly:generation', 1);
        Cache::forever('oracly:generation', $generation + 1);
    }

    public static function key(string $parts): string
    {
        $generation = (int) Cache::get('oracly:generation', 1);

        return "g{$generation}:{$parts}";
    }
}
