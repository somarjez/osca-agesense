<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add priority_flag to ml_results
        Schema::table('ml_results', function (Blueprint $table) {
            $table->string('priority_flag')->nullable()->after('overall_risk_level')
                  ->comment('Internal priority: maintenance|planned_monitoring|priority_action|urgent');
        });

        // Add priority_flag to recommendations
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('risk_level_new')->nullable()->after('risk_level');
        });

        // SQLite does not support ALTER COLUMN for enum changes.
        // Remap any existing CRITICAL values → HIGH across all tables.
        DB::table('ml_results')->where('overall_risk_level', 'CRITICAL')
            ->update(['overall_risk_level' => 'HIGH']);
        DB::table('ml_results')->where('ic_risk_level', 'critical')
            ->update(['ic_risk_level' => 'high']);
        DB::table('ml_results')->where('env_risk_level', 'critical')
            ->update(['env_risk_level' => 'high']);
        DB::table('ml_results')->where('func_risk_level', 'critical')
            ->update(['func_risk_level' => 'high']);
        DB::table('recommendations')->where('risk_level', 'critical')
            ->update(['risk_level' => 'high']);
        DB::table('recommendations')->where('urgency', 'immediate')
            ->update(['urgency' => 'urgent']);

        // Back-fill priority_flag for existing ml_results
        DB::statement("
            UPDATE ml_results
            SET priority_flag = CASE
                WHEN composite_risk >= 0.70 THEN 'urgent'
                WHEN composite_risk >= 0.50 THEN 'priority_action'
                WHEN composite_risk >= 0.30 THEN 'planned_monitoring'
                ELSE 'maintenance'
            END
            WHERE priority_flag IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropColumn('priority_flag');
        });
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn('risk_level_new');
        });
    }
};
