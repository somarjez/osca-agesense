<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * TC-DEP-06 (audit finding): the audit trail captured senior/QoL/
 * recommendation CRUD and report exports (ActivityLogObserver) but had no
 * Illuminate\Auth\Events\Login/Logout/Failed listener anywhere — login,
 * logout, and failed-login attempts left no trace at all. Registered
 * explicitly in AppServiceProvider::boot() (Event::listen()) rather than
 * relying on Laravel's implicit auto-discovery, since one class here handles
 * three different event types.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        $user = $this->resolveUser($event->user);
        if (! $user) {
            return;
        }
        ActivityLog::record('login', $user, "Login: {$user->email}");
    }

    public function handleLogout(Logout $event): void
    {
        // Logout::$user is typed nullable (Auth::logout() can theoretically
        // fire with nobody authenticated) — skip rather than interpolate a
        // null email into the description.
        $user = $this->resolveUser($event->user);
        if (! $user) {
            return;
        }
        ActivityLog::record('logout', $user, "Logout: {$user->email}");
    }

    /**
     * $event->user is set only when the submitted credentials resolved to a
     * real account (wrong password) — an attempt against a non-existent
     * email resolves no user at all, so this can have no domain-model
     * subject (ActivityLog::record()'s $subject is nullable for exactly
     * this reason). The attempted identifier is logged from $event-
     * >credentials (the login field, e.g. email) — never the password,
     * which Laravel's own Failed event does not expose in the first place.
     */
    public function handleFailed(Failed $event): void
    {
        $attempted = (string) ($event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown');

        ActivityLog::record('login_failed', $this->resolveUser($event->user), "Failed login attempt: {$attempted}");
    }

    /**
     * Auth events type $user as the Authenticatable interface, but
     * ActivityLog::record() needs a concrete Eloquent Model to read
     * subject_type/subject_id from. This app has exactly one Authenticatable
     * implementation (App\Models\User, which IS an Eloquent Model) — narrows
     * the type explicitly rather than loosening record()'s own signature.
     */
    private function resolveUser(?Authenticatable $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
