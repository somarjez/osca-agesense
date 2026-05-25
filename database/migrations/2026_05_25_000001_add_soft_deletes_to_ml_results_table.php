<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->softDeletes()->after('scored_at');
        });
    }

    public function down(): void
    {
        Schema::table('ml_results', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
