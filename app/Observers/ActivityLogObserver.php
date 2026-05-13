<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic observer wired to SeniorCitizen, QolSurvey, and Recommendation.
 * Each event writes one row to activity_logs so staff can trace who did what.
 */
class ActivityLogObserver
{
    public function created(Model $model): void
    {
        ActivityLog::record('created', $model, $this->describe('created', $model));
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        // Skip noise-only updates (e.g. updated_at only)
        unset($dirty['updated_at']);
        if (empty($dirty)) return;

        ActivityLog::record('updated', $model, $this->describe('updated', $model), [
            'changed_fields' => array_keys($dirty),
        ]);
    }

    public function deleted(Model $model): void
    {
        // Distinguish soft-delete (archived) from force-delete
        $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
            ? 'force_deleted'
            : 'archived';

        ActivityLog::record($action, $model, $this->describe($action, $model));
    }

    public function restored(Model $model): void
    {
        ActivityLog::record('restored', $model, $this->describe('restored', $model));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function describe(string $action, Model $model): string
    {
        $class = class_basename($model);
        $label = match ($class) {
            'SeniorCitizen'  => $model->full_name  ?? "ID {$model->getKey()}",
            'QolSurvey'      => "survey ID {$model->getKey()} (senior {$model->senior_citizen_id})",
            'Recommendation' => "rec ID {$model->getKey()} (senior {$model->senior_citizen_id})",
            default          => "ID {$model->getKey()}",
        };
        return "{$class} {$action}: {$label}";
    }
}
