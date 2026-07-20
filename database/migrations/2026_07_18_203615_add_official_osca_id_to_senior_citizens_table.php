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
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->string('official_osca_id')->nullable()->unique()
                ->after('osca_id')
                ->comment('Official OSCA ID issued by the OSCA office; null until assigned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn('official_osca_id');
        });
    }
};
