<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the "keep this page open" guidance added to the Batch Assessment
 * page. BulkUploadController::upload() redirects here specifically because
 * this page's 3s status poll drives the queue drain (see DrainsMlQueue) —
 * closing the tab drops scoring to a 10-minute cron-tick cadence. The page
 * used to actively tell staff the opposite ("You can safely close this
 * tab"); this modal (and the corrected inline copy) should only appear
 * right after a bulk upload that genuinely queued live work, not on every
 * visit to the page.
 */
class BatchPageStayOpenGuidanceTest extends TestCase
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
            ['email' => 'batchguidance-admin@osca.local'],
            ['name' => 'BatchGuidance Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function modal_renders_right_after_a_bulk_upload_with_a_live_batch(): void
    {
        Bus::fake();
        $batch = Bus::batch([])->name('Test Batch')->dispatch();

        $cacheKey = 'ml_batch_'.now()->format('YmdHis');
        Cache::put("{$cacheKey}:total", 50, now()->addHours(2));
        Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

        $response = $this->withSession(['bulk_success' => 'Imported 50 senior(s) successfully.'])
            ->get(route('ml.batch'));

        $response->assertOk();
        $response->assertSee('Keep this page open while assessment runs', false);
        $response->assertSee('oscaBatchStayHint:'.$batch->id, false);
    }

    #[Test]
    public function modal_does_not_render_without_a_live_resumable_batch(): void
    {
        // bulk_success flashed but nothing resumable — e.g. the upload
        // inserted rows but had no eligible seniors to score, or the batch
        // already finished by the time this request landed.
        $response = $this->withSession(['bulk_success' => 'Imported 3 senior(s) successfully.'])
            ->get(route('ml.batch'));

        $response->assertOk();
        $response->assertDontSee('Keep this page open while assessment runs', false);
    }

    #[Test]
    public function modal_does_not_render_on_a_plain_visit_with_a_live_batch_but_no_flash(): void
    {
        // A live batch exists (e.g. from a manual "Run Full Batch" click)
        // but this particular request has no bulk_success flash — a reload,
        // or a different tab/session picking up the same in-progress batch.
        Bus::fake();
        $batch = Bus::batch([])->name('Test Batch')->dispatch();

        $cacheKey = 'ml_batch_'.now()->format('YmdHis');
        Cache::put("{$cacheKey}:total", 50, now()->addHours(2));
        Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

        $response = $this->get(route('ml.batch'));

        $response->assertOk();
        $response->assertDontSee('Keep this page open while assessment runs', false);
    }

    #[Test]
    public function running_state_copy_no_longer_says_it_is_safe_to_close_the_tab(): void
    {
        $response = $this->get(route('ml.batch'));

        $response->assertOk();
        $response->assertDontSee('You can safely close this tab', false);
    }
}
