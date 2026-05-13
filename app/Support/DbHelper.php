<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DbHelper
{
    /**
     * Return a SQL expression that computes age in years from a date column,
     * compatible with both MySQL and SQLite.
     *
     * @param  string $column  Fully-qualified column name, e.g. "senior_citizens.date_of_birth"
     * @param  string $alias   Optional AS alias
     */
    public static function ageExpr(string $column, string $alias = 'age'): string
    {
        $as = $alias !== '' ? " as {$alias}" : '';

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "(CAST(strftime('%Y','now') AS INTEGER) - CAST(strftime('%Y', {$column}) AS INTEGER)"
                 . " - (strftime('%m-%d','now') < strftime('%m-%d', {$column}))){$as}";
        }

        return "TIMESTAMPDIFF(YEAR, {$column}, CURDATE()){$as}";
    }
}
