<?php

namespace App\Policies;

use App\Models\SeniorCitizen;
use App\Models\User;

/**
 * Mirrors the role gates already applied to routes/seniors.php (and the
 * surveys/ml/recommendations routes that operate on a SeniorCitizen) so
 * authorization is centralized and unit-testable in one place. Laravel 11
 * auto-discovers this by convention (SeniorCitizen -> SeniorCitizenPolicy);
 * no AuthServiceProvider registration is needed.
 *
 * This does not change who can do what — it documents/tests the existing
 * route-middleware behavior, it is not (yet) wired into the controllers.
 */
class SeniorCitizenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'encoder', 'viewer']);
    }

    public function view(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin', 'encoder', 'viewer']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'encoder']);
    }

    public function update(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin', 'encoder']);
    }

    public function delete(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin']);
    }

    public function restore(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin']);
    }

    public function forceDelete(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin']);
    }

    /**
     * PDF export — preserves current viewer behavior (viewers can export).
     */
    public function export(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin', 'encoder', 'viewer']);
    }
}
