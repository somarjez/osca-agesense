<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->json('xai_data')->nullable()->after('section_scores');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropColumn('xai_data');
        });
    }
};
