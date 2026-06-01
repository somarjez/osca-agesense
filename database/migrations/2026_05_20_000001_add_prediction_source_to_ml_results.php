<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            // notebook_cache = 283 seed seniors from senior_predictions.csv
            // live_model     = new seniors scored by live GBR/RFR pipeline
            // fallback       = PHP heuristic used when Python services unreachable
            $table->string('prediction_source', 20)->default('live_model')->after('model_version')
                ->comment('notebook_cache | live_model | fallback');

            $table->boolean('is_cached_prediction')->default(false)->after('prediction_source');

            // Separate from processed_at (job timestamp) — exact moment inference ran
            $table->timestamp('scored_at')->nullable()->after('processed_at');

            // Critical flag: HIGH risk + composite_risk >= 0.70 (formerly the CRITICAL enum value)
            $table->boolean('critical_flag')->default(false)->after('is_cached_prediction');
        });

        // Back-fill existing rows based on raw_output model_metadata
        // notebook_cache: rows where notebook_override_applied = true in raw_output
        DB::statement("
            UPDATE ml_results
            SET prediction_source   = 'notebook_cache',
                is_cached_prediction = 1,
                critical_flag        = (composite_risk >= 0.70 AND overall_risk_level = 'HIGH')
            WHERE raw_output IS NOT NULL
              AND JSON_EXTRACT(raw_output, '$.model_metadata.notebook_override_applied') = true
        ");

        // fallback: rows whose raw_output status contains 'fallback'
        DB::statement("
            UPDATE ml_results
            SET prediction_source   = 'fallback',
                is_cached_prediction = 0,
                critical_flag        = (composite_risk >= 0.70 AND overall_risk_level = 'HIGH')
            WHERE raw_output IS NOT NULL
              AND JSON_EXTRACT(raw_output, '$.status') LIKE '%fallback%'
              AND prediction_source = 'live_model'
        ");

        // critical_flag for all remaining rows not yet touched
        DB::statement("
            UPDATE ml_results
            SET critical_flag = (composite_risk >= 0.70 AND overall_risk_level = 'HIGH')
            WHERE critical_flag = 0
              AND composite_risk IS NOT NULL
        ");

        // scored_at defaults to processed_at for historical rows
        DB::statement('UPDATE ml_results SET scored_at = processed_at WHERE scored_at IS NULL AND processed_at IS NOT NULL');

        // Index for fast prediction_source filtering
        Schema::table('ml_results', function (Blueprint $table) {
            $table->index('prediction_source');
            $table->index('critical_flag');
        });
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropIndex(['prediction_source']);
            $table->dropIndex(['critical_flag']);
            $table->dropColumn(['prediction_source', 'is_cached_prediction', 'scored_at', 'critical_flag']);
        });
    }
};
