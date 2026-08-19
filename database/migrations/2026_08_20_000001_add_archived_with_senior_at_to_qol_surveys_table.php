<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks which trashed qol_surveys rows were soft-deleted BY a senior's
     * archive cascade (SeniorCitizenController::destroy()/bulkDestroy()), as
     * opposed to an admin individually deleting a survey via
     * SurveyController::qolDestroy(). restore()/bulkRestore() use this to
     * bring back only the surveys their archive took down — not surveys the
     * admin had already deleted on purpose before the archive happened.
     *
     * This mirrors the lesson from PR #237 (see
     * SeniorCitizenController::restore()'s docblock): a timestamp-window
     * approach to "which trashed rows belong to this archive" is not
     * reliable after the fact, so this is recorded explicitly at archive
     * time instead of inferred later.
     */
    public function up(): void
    {
        Schema::table('qol_surveys', function (Blueprint $table) {
            $table->timestamp('archived_with_senior_at')->nullable()->after('deleted_at');
            $table->index('archived_with_senior_at');
        });

        // One-time backfill for the existing archived backlog: without this,
        // every senior already sitting in the archive would restore with
        // zero surveys once restore() starts requiring the marker. Heuristic
        // (this migration is the only place timestamp inference is used,
        // and only for pre-existing data): a trashed survey belonging to a
        // senior that is ALSO currently trashed, deleted within a few
        // seconds of that senior's own deleted_at, is treated as archived
        // together with it. A survey deleted well before its senior's
        // archive correctly stays unmarked — that gap is exactly the bug
        // this migration's schema change fixes going forward.
        DB::table('senior_citizens')
            ->whereNotNull('deleted_at')
            ->select('id', 'deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($seniors) {
                foreach ($seniors as $senior) {
                    DB::table('qol_surveys')
                        ->where('senior_citizen_id', $senior->id)
                        ->whereNotNull('deleted_at')
                        ->whereBetween('deleted_at', [
                            Carbon::parse($senior->deleted_at)->subSeconds(5),
                            $senior->deleted_at,
                        ])
                        ->update(['archived_with_senior_at' => DB::raw('deleted_at')]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('qol_surveys', function (Blueprint $table) {
            $table->dropIndex(['archived_with_senior_at']);
            $table->dropColumn('archived_with_senior_at');
        });
    }
};
