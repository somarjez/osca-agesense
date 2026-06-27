<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the dashboard's hot aggregate queries. These speed up
 * reads only — no column, data, or behavior change.
 *
 * Notes on what is intentionally NOT added here (already covered):
 *  - ml_results already has (senior_citizen_id, id), which serves the
 *    MAX(id) GROUP BY senior_citizen_id "latest result" subquery.
 *  - senior_accessibility_metrics already has an index on senior_citizen_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dashboard "surveyed" count: WHERE status = 'processed' ... DISTINCT
        // senior_citizen_id. A status-leading composite lets MySQL satisfy the
        // filter and the distinct from one index instead of a status scan.
        Schema::table('qol_surveys', function (Blueprint $table) {
            $table->index(['status', 'senior_citizen_id'], 'qol_surveys_status_senior_idx');
        });

        // Pending recommendations: the hot path filters status = 'pending' first.
        // The existing (senior_citizen_id, status) index can't lead on status, so
        // add a status-leading composite for these list/count queries.
        Schema::table('recommendations', function (Blueprint $table) {
            $table->index(['status', 'senior_citizen_id'], 'recommendations_status_senior_idx');
        });
    }

    public function down(): void
    {
        Schema::table('qol_surveys', function (Blueprint $table) {
            $table->dropIndex('qol_surveys_status_senior_idx');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex('recommendations_status_senior_idx');
        });
    }
};
