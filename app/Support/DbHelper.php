<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DbHelper
{
    /**
     * Return a SQL expression that computes age in years from a date column,
     * compatible with MySQL, SQLite, and PostgreSQL.
     *
     * @param  string  $column  Fully-qualified column name, e.g. "senior_citizens.date_of_birth"
     * @param  string  $alias  Optional AS alias
     */
    public static function ageExpr(string $column, string $alias = 'age'): string
    {
        $as = $alias !== '' ? " as {$alias}" : '';

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "(CAST(strftime('%Y','now') AS INTEGER) - CAST(strftime('%Y', {$column}) AS INTEGER)"
                 ." - (strftime('%m-%d','now') < strftime('%m-%d', {$column}))){$as}";
        }

        if ($driver === 'pgsql') {
            return "EXTRACT(YEAR FROM AGE(CURRENT_DATE, {$column})){$as}";
        }

        return "TIMESTAMPDIFF(YEAR, {$column}, CURDATE()){$as}";
    }
}
