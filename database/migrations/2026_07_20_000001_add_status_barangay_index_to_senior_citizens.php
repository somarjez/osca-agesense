<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * senior_citizens already has single-column indexes on `status` and `barangay`
 * (2024_01_01_000001), but the ubiquitous `active()->where('barangay', ...)`
 * filter (dashboard, reports, list stats) can only use one of them per query.
 * A status-leading composite lets MySQL satisfy both from one index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->index(['status', 'barangay'], 'senior_citizens_status_barangay_idx');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropIndex('senior_citizens_status_barangay_idx');
        });
    }
};
