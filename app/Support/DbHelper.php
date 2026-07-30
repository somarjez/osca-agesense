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

    /**
     * Return a SQL expression that extracts the segment after the last "-" in
     * a "{PREFIX}-{YEAR}-{SEQ}" style column and casts it to an integer,
     * compatible with MySQL, SQLite, and PostgreSQL. Used by
     * SeniorCitizen::generateOscaId()/oscaIdMaxSequences() to find the
     * current max sequence number for a given OSCA ID prefix+year.
     *
     * @param  string  $column  Fully-qualified column name, e.g. "osca_id"
     * @param  string  $alias  Optional AS alias
     */
    public static function lastDashSegmentAsInt(string $column, string $alias = ''): string
    {
        $as = $alias !== '' ? " as {$alias}" : '';

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Format is always exactly "{PREFIX}-{YEAR}-{SEQ}" (3 parts) —
            // the prefix is stripped to letters-only by generateOscaId(), so
            // it never itself contains a "-".
            return "CAST(SPLIT_PART({$column}, '-', 3) AS INTEGER){$as}";
        }

        if ($driver === 'sqlite') {
            return "CAST(substr({$column}, instr({$column}, '-') + "
                 ."instr(substr({$column}, instr({$column}, '-') + 1), '-') + 1) AS INTEGER){$as}";
        }

        // MySQL: SUBSTRING_INDEX(..., -1) returns everything after the last "-".
        // Use single-quotes for the delimiter literal — double-quotes only work
        // here because MySQL's default sql_mode doesn't include ANSI_QUOTES;
        // under ANSI_QUOTES (or any other engine) a double-quoted literal is
        // read as an identifier, not a string.
        return "CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED){$as}";
    }

    /**
     * Return a SQL expression that truncates a datetime column to its date
     * part, compatible with MySQL, SQLite, and PostgreSQL. MySQL's DATE(...)
     * function has no direct Postgres equivalent (Postgres uses ::date).
     *
     * @param  string  $column  Fully-qualified column name, e.g. "snapshot_date"
     * @param  string  $alias  Optional AS alias
     */
    public static function dateOnlyExpr(string $column, string $alias = ''): string
    {
        $as = $alias !== '' ? " as {$alias}" : '';

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return "CAST({$column} AS DATE){$as}";
        }

        if ($driver === 'sqlite') {
            return "date({$column}){$as}";
        }

        return "DATE({$column}){$as}";
    }

    /**
     * Return a SQL expression that extracts a top-level JSON key as text,
     * compatible with MySQL, SQLite, and PostgreSQL. Used for searching
     * ProfileDraft::data (a JSON column) by a field inside the blob — MySQL's
     * JSON_UNQUOTE(JSON_EXTRACT(...)) has no Postgres equivalent (Postgres
     * uses the ->> operator).
     *
     * @param  string  $column  Fully-qualified column name, e.g. "data"
     * @param  string  $key  Top-level JSON key, e.g. "firstName"
     */
    public static function jsonTextExpr(string $column, string $key): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return "({$column}->>'{$key}')";
        }

        if ($driver === 'sqlite') {
            // SQLite's json_extract() already returns scalar strings
            // unquoted, unlike MySQL's JSON_EXTRACT().
            return "json_extract({$column}, '$.{$key}')";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}'))";
    }
}
