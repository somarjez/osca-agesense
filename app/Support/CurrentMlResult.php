<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Single definition of "a senior's current ml_result" — the highest-id
 * ml_results row that is itself not soft-deleted AND does not point at a
 * soft-deleted qol_survey.
 *
 * Before this existed, "latest" was redefined ad hoc in four places
 * (SeniorCitizen::latestMlResult(), Recommendation::scopeCurrent(),
 * RecommendationController::index()'s raw joinSub, and
 * MlResultStalenessObserver::markLatestStale()), none of which excluded an
 * "orphan" — an ml_results row left behind pointing at a qol_survey that was
 * later soft-deleted without cascading. That gap is what made re-running an
 * assessment after deleting the latest QoL survey silently update a row
 * nothing read: MlService::persistResults() correctly recomputed the older,
 * surviving survey's ml_result, but every "latest" lookup still resolved to
 * the newer orphaned row (see SurveyController::qolDestroy(), which now
 * cascades so orphans shouldn't occur in practice — this stays as the
 * shared, defensive definition every read path uses).
 *
 * The two raw-SQL surfaces below (perSeniorSubquery/correlatedMaxIdSql) also
 * close a second, independent gap: a whereRaw()/DB::table() query bypasses
 * Eloquent's SoftDeletes global scope entirely, so without an explicit
 * "deleted_at is null" filter here, a soft-deleted ml_results row itself
 * (not just an orphaned one) would still be counted — which is what made
 * RecommendationController::index() double-count a senior's recommendations
 * after a re-run (Issue 2).
 */
class CurrentMlResult
{
    /**
     * Derived table of {senior_citizen_id, ml_result_id} — one row per
     * senior, ml_result_id = MAX(id) among their valid (non-orphaned,
     * non-deleted) results. Drop-in replacement for the ad hoc
     * "MAX(id) ... GROUP BY senior_citizen_id" queries built directly
     * against ml_results.
     */
    public static function perSeniorSubquery(): QueryBuilder
    {
        return DB::table('ml_results')
            ->select('senior_citizen_id')
            ->selectRaw('MAX(id) as ml_result_id')
            ->whereNull('ml_results.deleted_at')
            ->where(function ($q) {
                $q->whereNull('ml_results.qol_survey_id')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('qol_surveys')
                            ->whereColumn('qol_surveys.id', 'ml_results.qol_survey_id')
                            ->whereNull('qol_surveys.deleted_at');
                    });
            })
            ->groupBy('senior_citizen_id');
    }

    /**
     * Raw correlated-subquery SQL fragment (no surrounding parens) returning
     * the current ml_result id for the senior referenced by $seniorColumn —
     * for whereRaw() call sites where a query builder isn't available
     * (e.g. Recommendation::scopeCurrent()).
     */
    public static function correlatedMaxIdSql(string $seniorColumn): string
    {
        return "select max(id) from ml_results where ml_results.senior_citizen_id = {$seniorColumn}"
            .' and ml_results.deleted_at is null'
            .' and (ml_results.qol_survey_id is null or exists ('
            .'select 1 from qol_surveys where qol_surveys.id = ml_results.qol_survey_id and qol_surveys.deleted_at is null'
            .'))';
    }

    /**
     * Constraint closure for Eloquent's ofMany()/latestOfMany() — e.g.
     * SeniorCitizen::latestMlResult() — and for any other Eloquent MlResult
     * query that needs the orphan exclusion. Only adds the qol_survey-orphan
     * check: the ml_results.deleted_at check is unnecessary here because
     * MlResult's own SoftDeletes global scope already applies whenever the
     * query goes through Eloquent (unlike the raw-SQL surfaces above).
     */
    public static function excludeOrphaned(): Closure
    {
        return function ($query) {
            $query->where(function ($q) {
                $q->whereNull('qol_survey_id')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('qol_surveys')
                            ->whereColumn('qol_surveys.id', 'qol_survey_id')
                            ->whereNull('qol_surveys.deleted_at');
                    });
            });
        };
    }
}
