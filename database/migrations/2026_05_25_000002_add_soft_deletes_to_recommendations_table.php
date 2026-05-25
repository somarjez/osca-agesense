<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->softDeletes()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
