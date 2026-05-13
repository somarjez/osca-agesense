<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('checkup_schedule');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('location_source')->nullable()->after('longitude');
            $table->string('location_accuracy')->nullable()->after('location_source');
            $table->timestamp('location_verified_at')->nullable()->after('location_accuracy');

            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn([
                'latitude',
                'longitude',
                'location_source',
                'location_accuracy',
                'location_verified_at',
            ]);
        });
    }
};
