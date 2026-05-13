<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->index('priority_flag');
            // Speeds up latestOfMany() subquery used on the seniors list page
            $table->index(['senior_citizen_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropIndex(['priority_flag']);
            $table->dropIndex(['senior_citizen_id', 'id']);
        });
    }
};
