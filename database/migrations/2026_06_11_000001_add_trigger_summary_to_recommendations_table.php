<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the human-readable trigger_summary field to recommendations.
     * trigger_summary describes WHAT fired each rule (e.g. "Reported hypertension
     * or high blood pressure."). It is produced by build_rec_from_rule() in
     * recommendation_rules.py and required by the v2 engine spec for defense /
     * auditability. domain and critical_flag already exist (recommendations.domain
     * and ml_results.critical_flag respectively).
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->text('trigger_summary')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn('trigger_summary');
        });
    }
};
