<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seniors index/dashboard/archives listing order by created_at (->latest()).
        // ml_results.composite_risk is already indexed by
        // 2026_04_27_000001_add_domain_risks_to_ml_results.php — no action needed
        // there.
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
