<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            // Rule-based domain-level risks (from _compute_rule_based_risk)
            $table->decimal('risk_medical',    5, 4)->nullable()->after('wellbeing_score')->comment('Medical/health domain risk');
            $table->decimal('risk_financial',  5, 4)->nullable()->after('risk_medical')->comment('Financial domain risk');
            $table->decimal('risk_social',     5, 4)->nullable()->after('risk_financial')->comment('Social domain risk');
            $table->decimal('risk_functional', 5, 4)->nullable()->after('risk_social')->comment('Functional domain risk');
            $table->decimal('risk_housing',    5, 4)->nullable()->after('risk_functional')->comment('Housing domain risk');
            $table->decimal('risk_hc_access',  5, 4)->nullable()->after('risk_housing')->comment('Healthcare access domain risk');
            $table->decimal('risk_sensory',    5, 4)->nullable()->after('risk_hc_access')->comment('Sensory domain risk');
            $table->decimal('rule_composite',  5, 4)->nullable()->after('risk_sensory')->comment('Weighted composite of all rule-based domain risks');

            // WHO Healthy Ageing domain scores (1–5 scale from QoL items)
            $table->decimal('ic_score',   5, 4)->nullable()->after('rule_composite')->comment('WHO Intrinsic Capacity score (1-5)');
            $table->decimal('env_score',  5, 4)->nullable()->after('ic_score')->comment('WHO Environment score (1-5)');
            $table->decimal('func_score', 5, 4)->nullable()->after('env_score')->comment('WHO Functional Ability score (1-5)');
            $table->decimal('qol_score',  5, 4)->nullable()->after('func_score')->comment('WHO Quality of Life score (1-5)');

            // Indexes for the most-queried domain risk fields
            $table->index('risk_medical');
            $table->index('risk_functional');
            $table->index('composite_risk');
        });
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropIndex(['risk_medical']);
            $table->dropIndex(['risk_functional']);
            $table->dropIndex(['composite_risk']);
            $table->dropColumn([
                'risk_medical', 'risk_financial', 'risk_social', 'risk_functional',
                'risk_housing', 'risk_hc_access', 'risk_sensory', 'rule_composite',
                'ic_score', 'env_score', 'func_score', 'qol_score',
            ]);
        });
    }
};
