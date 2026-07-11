<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Version stamp folded into every cache key that's parameterized per
 * filter combination (dashboard KPIs, GIS GeoJSON, cluster analytics),
 * where enumerating and forgetting every combo isn't practical and the
 * cache store (file) doesn't support tag-based flushing.
 *
 * Bumping the version orphans every previously-cached key variant at
 * once; orphaned entries are never read again and simply expire via
 * their own TTL, so no explicit cleanup is needed.
 */
class SeniorDataVersion
{
    private const KEY = 'senior_data.version';

    public static function current(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }

    public static function bump(): void
    {
        Cache::forever(self::KEY, now()->timestamp);
    }
}
