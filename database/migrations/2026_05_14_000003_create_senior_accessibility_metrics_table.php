<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('senior_accessibility_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_citizen_id')->constrained('senior_citizens')->cascadeOnDelete();
            $table->foreignId('nearest_health_center_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->decimal('distance_to_health_center_m', 10, 2)->nullable();
            $table->foreignId('nearest_barangay_hall_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->decimal('distance_to_barangay_hall_m', 10, 2)->nullable();
            $table->foreignId('nearest_market_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->decimal('distance_to_market_m', 10, 2)->nullable();
            $table->decimal('accessibility_score', 6, 4)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index('senior_citizen_id');
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senior_accessibility_metrics');
    }
};
