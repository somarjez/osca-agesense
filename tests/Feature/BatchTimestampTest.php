<?php

namespace Tests\Feature;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Carbon\Carbon;
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
        $this->withoutVite();

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
        Cache::put('ml_last_batch_started', $timestamp, now()->addDays(90));
        Cache::put('ml_last_batch_senior_count', 283, now()->addDays(90));

        $response = $this->actingAs($this->admin)->get(route('ml.batch'));

        $response->assertOk();
        $response->assertViewHas('lastBatchCount', 283);
        $viewTimestamp = $response->viewData('lastBatchRun');
        $this->assertInstanceOf(Carbon::class, $viewTimestamp,
            'lastBatchRun should be a Carbon instance.');
        $this->assertTrue($timestamp->equalTo($viewTimestamp),
            'lastBatchRun timestamp does not match the cached value.');
    }

    #[Test]
    public function batch_run_stores_start_timestamp_in_cache(): void
    {
        Cache::forget('ml_last_batch_started');

        Bus::fake();

        // Self-contained eligible senior — batchRun() requires at least one
        // senior with a processed QoL survey. This used to lean on whatever
        // real data happened to be in the shared dev database the test suite
        // ran against (see .env.testing); now that tests run against their
        // own isolated database, that data no longer exists by default, so
        // the fixture has to come from the test itself. DatabaseTransactions
        // wraps this test, so nothing here persists beyond it.
        $eligible = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Batch',
            'last_name' => 'Eligible',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);
        QolSurvey::create([
            'senior_citizen_id' => $eligible->id,
            'survey_version' => 'v1',
            'survey_date' => '2026-01-01',
            'status' => 'processed',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('ml.batch.run'));

        $response->assertOk();
        $this->assertNotNull(Cache::get('ml_last_batch_started'),
            'ml_last_batch_started was not written to cache after batchRun().');
        $this->assertGreaterThan(0, (int) Cache::get('ml_last_batch_senior_count'),
            'ml_last_batch_senior_count was not written or is zero.');
    }
}
