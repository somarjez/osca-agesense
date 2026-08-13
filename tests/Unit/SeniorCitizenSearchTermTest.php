<?php

namespace Tests\Unit;

use App\Models\SeniorCitizen;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the user-reported case-sensitive search issue: the
 * deployed production database is Postgres (Neon; see .env), where a plain
 * `->where('col', 'like', "%{$term}%")` is CASE-SENSITIVE — staff searching
 * "juan" found nothing for a senior stored as "Juan". Local MySQL's default
 * collation (utf8mb4_0900_ai_ci) is already case-insensitive, which is
 * exactly why this went unnoticed through the whole audit and is not, on its
 * own, a reliable way to prove the fix — a functional "mixed case still
 * matches" test would have passed on MySQL even with the OLD buggy code.
 * So this asserts the actual SQL shape (LOWER(...) wrapping, driver-agnostic)
 * that makes the behavior identical on both engines, in addition to the
 * functional check.
 */
class SeniorCitizenSearchTermTest extends TestCase
{
    #[Test]
    public function search_term_wraps_every_matched_column_in_lower_for_cross_driver_case_insensitivity(): void
    {
        $sql = SeniorCitizen::query()->searchTerm('Juan')->toSql();

        $this->assertStringContainsString('LOWER(first_name)', $sql);
        $this->assertStringContainsString('LOWER(last_name)', $sql);
        $this->assertStringContainsString('LOWER(osca_id)', $sql);
        $this->assertStringContainsString('LOWER(official_osca_id)', $sql);
        // Never a bare `like` with no LOWER() wrapping — that's exactly the
        // pattern that was case-sensitive on Postgres.
        $this->assertDoesNotMatchRegularExpression('/`?\w+`?\s+like\s+\?/i', $sql);
    }

    #[Test]
    public function search_term_lowercases_the_bound_needle_too(): void
    {
        $bindings = SeniorCitizen::query()->searchTerm('JUAN')->getBindings();

        foreach ($bindings as $binding) {
            $this->assertSame(strtolower($binding), $binding, "Binding '{$binding}' must already be lowercased.");
        }
    }
}
