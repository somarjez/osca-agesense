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
 * Deliberately does NOT hook `updated` — that fires on every field edit
 * (e.g. contact number) and would bust the whole dashboard cache far more
 * often than the "who's active" count actually changes.
 */
class SeniorDataVersionObserver
{
    public function created(SeniorCitizen $senior): void
    {
        SeniorDataVersion::bump();
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
