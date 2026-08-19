<?php

namespace Tests\Unit;

use App\Models\Facility;
use App\Models\MlResult;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a production outage: PDO::ATTR_EMULATE_PREPARES
 * (config/database.php, needed for Neon's pooled PgBouncer endpoint — see
 * PgsqlEmulatedPreparesTest) makes pdo_pgsql stringify a bound PHP `true`/
 * `false` as the bare integer literal 1/0 instead of a proper boolean
 * literal. Postgres has no `boolean = integer` operator, so every
 * `->where('boolean_column', true)` call started failing with
 * SQLSTATE[42883] the moment emulated prepares went live — MlController's
 * /ml/status page 500'd for every admin.
 *
 * Local tests run against MySQL, which is permissive enough that this bug
 * is invisible there — `boolean_column = 1` never errors on MySQL either
 * way. So this test doesn't execute the query; it inspects the compiled
 * SQL/bindings directly, which is the only way a local run can catch a
 * regression back to a bound PHP boolean before it reaches production.
 * DB::raw('true')/'false' compiles as an unparameterized literal, so it
 * never goes through PDO's boolean-value quoting path at all — safe under
 * both native and emulated prepares, on both drivers.
 */
class BooleanColumnComparisonsAvoidBoundLiteralsTest extends TestCase
{
    #[Test]
    public function ml_result_critical_flag_comparison_is_not_a_bound_boolean(): void
    {
        $query = MlResult::query()->where('critical_flag', DB::raw('true'));

        $this->assertStringContainsString('true', $query->toSql());
        $this->assertNotContains(true, $query->getBindings());
        $this->assertNotContains(1, $query->getBindings());
    }

    #[Test]
    public function facility_is_active_comparison_is_not_a_bound_boolean(): void
    {
        $query = Facility::query()->where('is_active', DB::raw('true'));

        $this->assertStringContainsString('true', $query->toSql());
        $this->assertNotContains(true, $query->getBindings());
        $this->assertNotContains(1, $query->getBindings());
    }
}
