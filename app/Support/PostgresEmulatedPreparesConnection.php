<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\PostgresConnection;

/**
 * Fixes bound PHP booleans under PDO::ATTR_EMULATE_PREPARES (config/database.php's
 * pgsql connection — required for Neon's pooled PgBouncer endpoint; see
 * PgsqlEmulatedPreparesTest).
 *
 * Illuminate\Database\Connection::prepareBindings() unconditionally casts a
 * bound PHP bool to (int) before it reaches PDO, for every driver. Under
 * normal (server-side) prepared statements this is harmless — Postgres
 * receives the value as an untyped parameter over the wire and coerces it to
 * whatever the target column needs. But PDO::ATTR_EMULATE_PREPARES makes PDO
 * substitute bound values directly into the SQL text on the client side
 * instead, so that (int) cast becomes a bare, TYPED integer literal (0/1)
 * once embedded — and Postgres has no implicit assignment cast from integer
 * to boolean, so any INSERT/UPDATE touching a boolean column fails with
 * SQLSTATE[42804] ("column is of type boolean but expression is of type
 * integer"). BooleanColumnComparisonsAvoidBoundLiteralsTest already covers
 * this for WHERE-clause comparisons (worked around per-call-site with
 * DB::raw('true')/'false'); this class fixes it at the connection level so
 * every INSERT/UPDATE against a boolean column (has_medical_checkup,
 * is_active, is_cached_prediction, critical_flag, is_stale,
 * requires_human_validation, …) works without a per-call-site workaround.
 *
 * A quoted string literal ('true'/'false'), unlike a bare integer literal, is
 * an "unknown"-typed constant in Postgres — it implicitly coerces to
 * whatever the target column/context needs, which is why we emit that
 * instead of an int. Safe under both native and emulated prepares: Postgres
 * accepts 'true'/'false' as valid boolean literal text either way.
 */
class PostgresEmulatedPreparesConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }
}
