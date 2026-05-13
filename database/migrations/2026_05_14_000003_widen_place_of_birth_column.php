<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->text('place_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Cannot safely shrink back to varchar(255) once encrypted values are stored
    }
};
