<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_accessibility_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('senior_accessibility_metrics', 'nearest_hospital_id')) {
                $table->foreignId('nearest_hospital_id')
                    ->nullable()
                    ->after('distance_to_health_center_m')
                    ->constrained('facilities')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('senior_accessibility_metrics', 'distance_to_hospital_m')) {
                $table->decimal('distance_to_hospital_m', 10, 2)
                    ->nullable()
                    ->after('nearest_hospital_id');
            }

            if (! Schema::hasColumn('senior_accessibility_metrics', 'nearest_pharmacy_id')) {
                $table->foreignId('nearest_pharmacy_id')
                    ->nullable()
                    ->after('distance_to_hospital_m')
                    ->constrained('facilities')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('senior_accessibility_metrics', 'distance_to_pharmacy_m')) {
                $table->decimal('distance_to_pharmacy_m', 10, 2)
                    ->nullable()
                    ->after('nearest_pharmacy_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('senior_accessibility_metrics', function (Blueprint $table) {
            if (Schema::hasColumn('senior_accessibility_metrics', 'nearest_pharmacy_id')) {
                $table->dropConstrainedForeignId('nearest_pharmacy_id');
            }

            if (Schema::hasColumn('senior_accessibility_metrics', 'nearest_hospital_id')) {
                $table->dropConstrainedForeignId('nearest_hospital_id');
            }

            if (Schema::hasColumn('senior_accessibility_metrics', 'distance_to_pharmacy_m')) {
                $table->dropColumn('distance_to_pharmacy_m');
            }

            if (Schema::hasColumn('senior_accessibility_metrics', 'distance_to_hospital_m')) {
                $table->dropColumn('distance_to_hospital_m');
            }
        });
    }
};
