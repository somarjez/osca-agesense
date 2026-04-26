<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert existing string values to JSON arrays before altering column type.
        foreach (['dental_concern', 'optical_concern', 'hearing_concern', 'healthcare_difficulty'] as $col) {
            DB::table('senior_citizens')
                ->whereNotNull($col)
                ->where($col, 'not like', '[%')
                ->lazyById()
                ->each(function ($row) use ($col) {
                    DB::table('senior_citizens')
                        ->where('id', $row->id)
                        ->update([$col => json_encode([$row->$col])]);
                });
        }

        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->json('dental_concern')->nullable()->change();
            $table->json('optical_concern')->nullable()->change();
            $table->json('hearing_concern')->nullable()->change();
            $table->json('healthcare_difficulty')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->string('dental_concern')->nullable()->change();
            $table->string('optical_concern')->nullable()->change();
            $table->string('hearing_concern')->nullable()->change();
            $table->string('healthcare_difficulty')->nullable()->change();
        });
    }
};
