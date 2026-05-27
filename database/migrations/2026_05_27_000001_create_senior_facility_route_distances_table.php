<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('senior_facility_route_distances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_citizen_id')->constrained('senior_citizens')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->decimal('origin_latitude', 10, 7);
            $table->decimal('origin_longitude', 10, 7);
            $table->decimal('destination_latitude', 10, 7);
            $table->decimal('destination_longitude', 10, 7);
            $table->decimal('route_distance_m', 10, 2);
            $table->decimal('route_duration_s', 10, 2)->nullable();
            $table->string('provider', 40)->default('openrouteservice');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['senior_citizen_id', 'facility_id'], 'senior_facility_route_unique');
            $table->index(['senior_citizen_id', 'route_distance_m'], 'senior_route_distance_idx');
            $table->index('facility_id');
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senior_facility_route_distances');
    }
};
