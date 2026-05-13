<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MAP = [
        'Lack of source of income/resources' => 'Lack of income/resources',
        'Lack of source of income'           => 'Lack of income/resources',
        'Lack of income'                     => 'Lack of income/resources',
        'Loss of source of income/resources' => 'Loss of income/resources',
        'Loss of source of income'           => 'Loss of income/resources',
        'Loss of income'                     => 'Loss of income/resources',
    ];

    public function up(): void
    {
        DB::table('senior_citizens')
            ->whereNotNull('problems_needs')
            ->get(['id', 'problems_needs'])
            ->each(function ($row) {
                $items = json_decode($row->problems_needs, true);
                if (!is_array($items)) {
                    return;
                }

                $normalized = array_values(array_unique(
                    array_map(fn($item) => self::MAP[$item] ?? $item, $items)
                ));

                if ($normalized !== $items) {
                    DB::table('senior_citizens')
                        ->where('id', $row->id)
                        ->update(['problems_needs' => json_encode($normalized)]);
                }
            });
    }

    public function down(): void
    {
        // Not reversible — original CSV strings are gone
    }
};
