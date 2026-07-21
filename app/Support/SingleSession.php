<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enforces "one active session per account".
 *
 * The app uses the database session driver, so every active login is a row in
 * the `sessions` table keyed by `user_id` with a `last_activity` timestamp.
 * A session is considered "active" only if it has been used within the idle
 * threshold; older rows are treated as stale and may be reclaimed on login.
 */
class SingleSession
{
    /**
     * How long (in seconds) a session may sit idle before it is reclaimable.
     */
    public static function idleThresholdSeconds(): int
    {
        return (int) config('auth.single_session_idle_minutes', 20) * 60;
    }

    /**
     * True when $user has a non-idle session other than $currentSessionId.
     */
    public static function activeElsewhere(User $user, ?string $currentSessionId): bool
    {
        $cutoff = time() - self::idleThresholdSeconds();

        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentSessionId, fn ($q) => $q->where('id', '!=', $currentSessionId))
            ->where('last_activity', '>=', $cutoff)
            ->exists();
    }

    /**
     * Remove $user's other session rows (stale/idle reclaim on a fresh login).
     */
    public static function releaseOthers(User $user, ?string $currentSessionId): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentSessionId, fn ($q) => $q->where('id', '!=', $currentSessionId))
            ->delete();
    }
}
