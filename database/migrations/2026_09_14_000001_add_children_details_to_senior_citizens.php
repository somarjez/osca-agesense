<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            // Per-child detail rows for the Family Composition step: array of
            // {full_name, age, gender, employment_status, occupation, marital_status, address}.
            // Count is kept in sync with num_children by the form, not the DB.
            $table->json('children_details')->nullable()->after('num_working_children');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn('children_details');
        });
    }
};
