<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage: production connects through Neon's pooled endpoint
 * (PgBouncer, transaction-pooling mode) so a single Render instance doesn't
 * pay a fresh-connection handshake on every request. Transaction pooling can
 * hand a "session" a different physical connection between statements, which
 * broke PDO's default native server-side prepared statements in production —
 * every query after the first failed with SQLSTATE[25P02] ("current
 * transaction is aborted, commands ignored until end of transaction block").
 * config/database.php's pgsql connection now sets PDO::ATTR_EMULATE_PREPARES
 * to avoid relying on server-side statement state surviving a pooled
 * connection handoff. If this key is ever removed, the pgsql environment
 * this project deploys to breaks entirely — nothing local (MySQL) would
 * catch that, so this test asserts the config directly instead.
 */
class PgsqlEmulatedPreparesTest extends TestCase
{
    #[Test]
    public function pgsql_connection_emulates_prepared_statements(): void
    {
        $options = config('database.connections.pgsql.options');

        if (! extension_loaded('pdo_pgsql')) {
            $this->assertSame([], $options);

            return;
        }

        $this->assertSame(true, $options[\PDO::ATTR_EMULATE_PREPARES] ?? null);
    }
}
