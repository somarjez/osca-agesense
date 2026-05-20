<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            // Staleness tracking — set true when profile or QoL data changes after scoring.
            // The result is NOT deleted; it is preserved until the next analysis overwrites it.
            // On next runPipeline() call the stale row is recomputed and these fields are cleared.
            $table->boolean('is_stale')->default(false)->after('is_cached_prediction');
            $table->string('stale_reason')->nullable()->after('is_stale');
            $table->timestamp('stale_at')->nullable()->after('stale_reason');
        });

        Schema::table('ml_results', function (Blueprint $table) {
            $table->index('is_stale');
        });
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropIndex(['is_stale']);
            $table->dropColumn(['is_stale', 'stale_reason', 'stale_at']);
        });
    }
};
