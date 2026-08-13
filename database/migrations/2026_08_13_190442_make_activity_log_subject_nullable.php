<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TC-DEP-06 (audit finding): login/logout/failed-login are not captured in
 * the audit trail. Those events have no natural domain-model subject (a
 * failed login attempt against a non-existent email resolves no User at
 * all), but subject_type/subject_id were NOT NULL — see
 * App\Listeners\LogAuthenticationActivity and ActivityLog::record()'s
 * now-nullable $subject parameter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('subject_type', 128)->nullable()->change();
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('subject_type', 128)->nullable(false)->change();
            $table->unsignedBigInteger('subject_id')->nullable(false)->change();
        });
    }
};
