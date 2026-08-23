<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
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

    /**
     * How long a recorded blocked-login attempt stays visible to the active
     * session before it's considered stale. Short-lived on purpose: this
     * only needs to survive one poll cycle of the active session's client
     * (resources/js/login-attempt-watch.js), not serve as an audit trail.
     */
    private const ATTEMPT_TTL_SECONDS = 30;

    /**
     * Record that a login was just blocked for $user because their account
     * is active elsewhere, so the currently-active session can be notified
     * on its next poll. Called from the blocked branch in routes/auth.php.
     */
    public static function recordAttempt(User $user, string $ip): void
    {
        Cache::put(self::attemptCacheKey($user->id), [
            'ip' => $ip,
            'at' => now()->toIso8601String(),
        ], self::ATTEMPT_TTL_SECONDS);
    }

    /**
     * Read-and-clear: returns the pending blocked-attempt record for
     * $userId, if any, and removes it so it's only ever surfaced once. Used
     * by the poll endpoint the active session's browser hits periodically.
     */
    public static function pullAttempt(int $userId): ?array
    {
        $key = self::attemptCacheKey($userId);
        $attempt = Cache::get($key);

        if ($attempt) {
            Cache::forget($key);
        }

        return $attempt;
    }

    private static function attemptCacheKey(int $userId): string
    {
        return "single_session_attempt:{$userId}";
    }
}
