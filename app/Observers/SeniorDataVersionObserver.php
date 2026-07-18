<?php

namespace App\Observers;

use App\Models\SeniorCitizen;
use App\Support\SeniorDataVersion;

/**
 * Bumps SeniorDataVersion whenever the set of active seniors changes, so
 * the version-stamped caches (dashboard KPIs, GIS map, cluster analytics)
 * pick up archive/restore/delete/create immediately instead of waiting
 * out their TTL.
 *
 * `updated` is intentionally narrow (see below) rather than unhooked — it
 * fires on every field edit (e.g. contact number), which would bust the
 * whole dashboard cache far more often than the "who's active" count
 * actually changes, so it only bumps when `status` itself changed.
 */
class SeniorDataVersionObserver
{
    public function created(SeniorCitizen $senior): void
    {
        SeniorDataVersion::bump();
    }

    /**
     * status is the one field edit that changes "who's active" (e.g. marking
     * a senior deceased), so it gets a narrow exception to the no-`updated`
     * rule above — every other field edit is still ignored.
     */
    public function updated(SeniorCitizen $senior): void
    {
        if ($senior->wasChanged('status')) {
            SeniorDataVersion::bump();
        }
    }

    public function deleted(SeniorCitizen $senior): void
    {
        SeniorDataVersion::bump();
    }

    public function restored(SeniorCitizen $senior): void
    {
        SeniorDataVersion::bump();
    }

    public function forceDeleted(SeniorCitizen $senior): void
    {
        SeniorDataVersion::bump();
    }
}
