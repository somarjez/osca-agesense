<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActivityLogDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->encoder = User::firstOrCreate(
            ['email' => 'encoder@osca.local'],
            ['name' => 'OSCA Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);
    }

    /** Create N activity log entries owned by the admin user. */
    private function createLogs(int $count): Collection
    {
        $senior = SeniorCitizen::first();   // uses seeded data

        $logs = collect();
        for ($i = 0; $i < $count; $i++) {
            $logs->push(ActivityLog::create([
                'user_id' => $this->admin->id,
                'action' => 'created',
                'subject_type' => SeniorCitizen::class,
                'subject_id' => $senior?->id ?? 1,
                'description' => "Test log entry {$i}",
                'ip_address' => '127.0.0.1',
            ]));
        }

        return $logs;
    }

    // ── bulkDestroy ───────────────────────────────────────────────────────

    #[Test]
    public function bulk_destroy_deletes_only_selected_log_entries(): void
    {
        $logs = $this->createLogs(3);
        $toDelete = $logs->take(2)->pluck('id')->toArray();
        $keep = $logs->last()->id;

        $this->actingAs($this->admin)
            ->delete(route('activity-log.bulk-destroy'), ['ids' => $toDelete])
            ->assertRedirect();

        foreach ($toDelete as $id) {
            $this->assertDatabaseMissing('activity_logs', ['id' => $id]);
        }
        $this->assertDatabaseHas('activity_logs', ['id' => $keep]);
    }

    #[Test]
    public function bulk_destroy_requires_ids_to_be_present(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('activity-log.bulk-destroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    #[Test]
    public function bulk_destroy_is_forbidden_for_encoder(): void
    {
        $logs = $this->createLogs(2);

        $this->actingAs($this->encoder)
            ->delete(route('activity-log.bulk-destroy'), ['ids' => $logs->pluck('id')->toArray()])
            ->assertForbidden();

        // Entries must still exist.
        foreach ($logs as $log) {
            $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
        }
    }

    #[Test]
    public function bulk_destroy_requires_authentication(): void
    {
        $logs = $this->createLogs(2);
        $this->delete(route('activity-log.bulk-destroy'), ['ids' => $logs->pluck('id')->toArray()])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function bulk_destroy_writes_a_warning_to_the_standard_log_channel(): void
    {
        Log::spy();

        $logs = $this->createLogs(2);
        $ids = $logs->pluck('id')->toArray();

        $this->actingAs($this->admin)
            ->delete(route('activity-log.bulk-destroy'), ['ids' => $ids])
            ->assertRedirect();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Activity log entries deleted', \Mockery::on(function ($context) {
                return $context['action'] === 'bulk_destroy'
                    && $context['count'] === 2
                    && array_key_exists('user_id', $context)
                    && array_key_exists('ip', $context);
            }));
    }

    // ── clear ─────────────────────────────────────────────────────────────

    #[Test]
    public function clear_deletes_all_log_entries_created_in_test(): void
    {
        // Count rows that exist BEFORE this test creates anything.
        $baseline = ActivityLog::count();
        $this->createLogs(4);
        $this->assertEquals($baseline + 4, ActivityLog::count());

        $this->actingAs($this->admin)
            ->delete(route('activity-log.clear'))
            ->assertRedirect(route('activity-log.index'));

        // DatabaseTransactions wraps all rows (including pre-existing seed data) in the
        // same transaction, so query()->delete() empties the table completely. The
        // transaction is rolled back after the test restoring all rows.
        $this->assertEquals(0, ActivityLog::count());
    }

    #[Test]
    public function clear_is_forbidden_for_encoder(): void
    {
        $this->createLogs(2);
        $countBefore = ActivityLog::count();

        $this->actingAs($this->encoder)
            ->delete(route('activity-log.clear'))
            ->assertForbidden();

        $this->assertEquals($countBefore, ActivityLog::count());
    }

    #[Test]
    public function clear_requires_authentication(): void
    {
        $this->delete(route('activity-log.clear'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function clear_writes_a_warning_to_the_standard_log_channel(): void
    {
        Log::spy();

        $this->createLogs(3);
        $countBefore = ActivityLog::count();

        $this->actingAs($this->admin)
            ->delete(route('activity-log.clear'))
            ->assertRedirect(route('activity-log.index'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Activity log entries deleted', \Mockery::on(function ($context) use ($countBefore) {
                return $context['action'] === 'clear'
                    && $context['count'] === $countBefore
                    && array_key_exists('user_id', $context)
                    && array_key_exists('ip', $context);
            }));
    }
}
