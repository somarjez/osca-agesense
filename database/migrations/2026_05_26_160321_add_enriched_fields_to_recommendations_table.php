<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add enriched recommendation fields produced by the v2 recommendation engine.
     * All columns are nullable so existing rows and old ML payloads are unaffected.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // Structured rule code (e.g. "FIN-001", "HLT-002") — null for disease-specific actions
            $table->string('recommendation_code', 20)->nullable()->after('action');
            // Philippine service provider(s) relevant to the recommendation
            $table->string('service_provider', 255)->nullable()->after('recommendation_code');
            // Evidence / policy source (program name or law)
            $table->text('evidence_source')->nullable()->after('service_provider');
            // Why this recommendation was triggered (eligibility rationale)
            $table->text('eligibility_basis')->nullable()->after('evidence_source');
            // Documents the senior may need to prepare
            $table->json('documents_needed')->nullable()->after('eligibility_basis');
            // Always true from ML output; OSCA staff should validate before acting
            $table->boolean('requires_human_validation')->default(true)->after('documents_needed');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'recommendation_code',
                'service_provider',
                'evidence_source',
                'eligibility_basis',
                'documents_needed',
                'requires_human_validation',
            ]);
        });
    }
};
