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
        Schema::create('profile_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_citizen_id')->nullable()->constrained('senior_citizens')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('step')->default(1);
            $table->json('data');
            $table->timestamps();

            $table->unique('senior_citizen_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_drafts');
    }
};
