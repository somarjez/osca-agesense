<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Persists "an ML job is currently queued for this senior" across page
        // reloads. Without this, the re-run/batch/bulk-upload flows only track
        // progress in ephemeral client-side Alpine state — on Render's free
        // tier, where the queue only drains every ~10 minutes (Phase E), the
        // page always outlives that state, making a genuinely-still-queued job
        // indistinguishable from a lost one. Set right before dispatch, cleared
        // when a result lands or the job fails — see MlController, MlService,
        // ProcessMlSingle, ProcessMlBatch.
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->timestamp('ml_queued_at')->nullable()->after('status_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn('ml_queued_at');
        });
    }
};
