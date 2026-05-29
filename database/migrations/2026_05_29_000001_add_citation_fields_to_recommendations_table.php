<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add APA citation and source-type fields to recommendations.
     * These are populated from EVIDENCE_SOURCES in recommendation_rules.py
     * via build_rec_from_rule() and are required by the v2 engine spec.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // APA-style bibliographic reference for the evidence source
            $table->text('apa_reference')->nullable()->after('evidence_source');
            // Broad source category: "Philippine law", "WHO framework", etc.
            $table->string('source_type', 100)->nullable()->after('apa_reference');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn(['apa_reference', 'source_type']);
        });
    }
};
