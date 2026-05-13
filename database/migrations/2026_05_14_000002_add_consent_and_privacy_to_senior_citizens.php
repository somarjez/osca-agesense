<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->timestamp('consent_given_at')->nullable()->after('encoded_by');
            $table->string('consent_method')->nullable()->after('consent_given_at')
                  ->comment('verbal, written, digital');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn(['consent_given_at', 'consent_method']);
        });
    }
};
