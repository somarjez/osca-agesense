<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the assumption the single-session feature depends on: under the
 * `database` session driver, a real authenticated request writes a row into
 * the `sessions` table scoped to the logged-in user's id. If this ever stops
 * being true (e.g. driver switched to file/redis/cookie), enforcement silently
 * no-ops — so this test pins the contract.
 */
class SessionDriverWritesUserIdTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'database']);

        User::create([
            'name' => 'Driver Probe',
            'email' => 'driverprobe@osca.local',
            'password' => Hash::make('correct-password'),
        ]);
    }

    #[Test]
    public function a_real_login_writes_a_user_scoped_row_to_the_sessions_table(): void
    {
        $user = User::where('email', 'driverprobe@osca.local')->firstOrFail();

        $this->post('/login', [
            'email' => 'driverprobe@osca.local',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertTrue(
            DB::table('sessions')->where('user_id', $user->id)->exists(),
            'Expected the database session driver to persist a sessions row with user_id.'
        );
    }
}
