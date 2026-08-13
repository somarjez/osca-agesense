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
 * This documents/tests the existing route-middleware behavior and is wired
 * into the controllers and Livewire components via `$this->authorize()`
 * calls throughout the app.
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
     * PDF export — admin/encoder only (TC-SEC-07). Previously allowed the
     * viewer role too; that was inconsistent with the rest of the app's own
     * privacy posture toward viewers — CoordinatePrivacy deliberately
     * generalizes/jitters GPS coordinates for the viewer role on the GIS
     * map, but the exported PDF (seniors/pdf.blade.php) contains the full,
     * unredacted profile, ML result, and QoL survey. Product decision:
     * restrict to match every other write-adjacent/high-sensitivity
     * capability in this policy, which is already admin/encoder-only.
     */
    public function export(User $user, SeniorCitizen $senior): bool
    {
        return $user->hasAnyRole(['admin', 'encoder']);
    }
}
