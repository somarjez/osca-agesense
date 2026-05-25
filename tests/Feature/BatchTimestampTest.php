<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BatchTimestampTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

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
    }

    #[Test]
    public function batch_index_passes_null_when_cache_is_empty(): void
    {
        Cache::forget('ml_last_batch_started');
        Cache::forget('ml_last_batch_senior_count');

        $response = $this->actingAs($this->admin)->get(route('ml.batch'));

        $response->assertOk();
        $response->assertViewHas('lastBatchRun', null);
        $response->assertViewHas('lastBatchCount', null);
    }

    #[Test]
    public function batch_index_passes_cached_timestamp_to_view(): void
    {
        $timestamp = now()->subHours(3);
        Cache::put('ml_last_batch_started',      $timestamp, now()->addDays(90));
        Cache::put('ml_last_batch_senior_count', 283,        now()->addDays(90));

        $response = $this->actingAs($this->admin)->get(route('ml.batch'));

        $response->assertOk();
        $response->assertViewHas('lastBatchRun',   $timestamp);
        $response->assertViewHas('lastBatchCount', 283);
    }

    #[Test]
    public function batch_run_stores_start_timestamp_in_cache(): void
    {
        Cache::forget('ml_last_batch_started');

        Bus::fake();

        // The seeded database has seniors with processed QoL surveys.
        // DatabaseTransactions wraps this test, so no records are modified.
        $response = $this->actingAs($this->admin)
            ->postJson(route('ml.batch.run'));

        // If no eligible seniors exist the endpoint returns 422 — skip assertion.
        if ($response->status() === 422) {
            $this->markTestSkipped('No eligible seniors in test DB — seed first.');
        }

        $response->assertOk();
        $this->assertNotNull(Cache::get('ml_last_batch_started'),
            'ml_last_batch_started was not written to cache after batchRun().');
        $this->assertGreaterThan(0, (int) Cache::get('ml_last_batch_senior_count'),
            'ml_last_batch_senior_count was not written or is zero.');
    }
}
