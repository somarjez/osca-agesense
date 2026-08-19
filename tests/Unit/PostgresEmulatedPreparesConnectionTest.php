<?php

namespace Tests\Unit;

use App\Support\PostgresEmulatedPreparesConnection;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a production bug: bulk-importing a senior failed
 * with SQLSTATE[42804] ("column has_medical_checkup is of type boolean but
 * expression is of type integer") the moment the row included a boolean
 * value, because Illuminate\Database\Connection::prepareBindings() casts
 * every bound PHP bool to (int) before it reaches PDO — and under
 * PDO::ATTR_EMULATE_PREPARES (config/database.php, required for Neon's
 * pooled endpoint) that int gets embedded as a bare, typed integer literal
 * that Postgres refuses to implicitly cast to boolean for a column value.
 * See PostgresEmulatedPreparesConnection's own docblock for the full
 * mechanism — this only failed against real Postgres, never locally against
 * MySQL, so (like BooleanColumnComparisonsAvoidBoundLiteralsTest) this
 * inspects prepareBindings()'s output directly rather than executing a query.
 */
class PostgresEmulatedPreparesConnectionTest extends TestCase
{
    private function makeConnection(): PostgresEmulatedPreparesConnection
    {
        return new PostgresEmulatedPreparesConnection(fn () => null, 'testing', '', []);
    }

    #[Test]
    public function true_is_prepared_as_the_string_literal_not_an_integer(): void
    {
        $bindings = $this->makeConnection()->prepareBindings(['has_medical_checkup' => true]);

        $this->assertSame('true', $bindings['has_medical_checkup']);
    }

    #[Test]
    public function false_is_prepared_as_the_string_literal_not_an_integer(): void
    {
        $bindings = $this->makeConnection()->prepareBindings(['has_medical_checkup' => false]);

        $this->assertSame('false', $bindings['has_medical_checkup']);
    }

    #[Test]
    public function non_boolean_bindings_pass_through_unchanged(): void
    {
        $bindings = $this->makeConnection()->prepareBindings([
            'first_name' => 'Test',
            'num_children' => 4,
        ]);

        $this->assertSame('Test', $bindings['first_name']);
        $this->assertSame(4, $bindings['num_children']);
    }

    #[Test]
    public function datetime_bindings_are_still_formatted_using_the_grammars_date_format(): void
    {
        $connection = $this->makeConnection();
        $date = new \DateTimeImmutable('2026-08-20 10:00:00');

        $bindings = $connection->prepareBindings(['created_at' => $date]);

        $this->assertSame($date->format($connection->getQueryGrammar()->getDateFormat()), $bindings['created_at']);
    }

    #[Test]
    public function the_pgsql_driver_resolves_to_this_connection_class(): void
    {
        $resolver = Connection::getResolver('pgsql');

        $this->assertNotNull($resolver, 'AppServiceProvider must register a pgsql resolver — see its register() method.');

        $connection = $resolver(fn () => null, 'testing', '', []);

        $this->assertInstanceOf(PostgresEmulatedPreparesConnection::class, $connection);
    }
}
