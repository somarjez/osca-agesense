<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            // When the senior was registered with the OSCA office. Nullable and
            // left blank for existing records (their created_at is the seed/import
            // date, not a real registration date); staff fill it in on edit.
            $table->date('registration_date')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn('registration_date');
        });
    }
};
