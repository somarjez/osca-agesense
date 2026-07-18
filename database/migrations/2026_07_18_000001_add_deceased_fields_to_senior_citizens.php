<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->date('date_of_death')->nullable()->after('status');
            $table->text('deceased_note')->nullable()->after('date_of_death');
            $table->string('status_changed_by')->nullable()->after('deceased_note')
                ->comment('Name/identifier of the user who last changed status');
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('senior_citizens', function (Blueprint $table) {
            $table->dropColumn(['date_of_death', 'deceased_note', 'status_changed_by', 'status_changed_at']);
        });
    }
};
