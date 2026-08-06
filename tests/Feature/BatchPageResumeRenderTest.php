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
 * Regression guard for the runtime-injected sibling of the bug
 * BladeAlpineAttributeIntegrityTest guards against: that test scans *source*
 * for a literal `"` inside a `//` comment terminating an x-data="..."
 * attribute early. This bug has the identical symptom (raw JS dumped as
 * visible page text) but a different cause the source scanner cannot see:
 * the Blade "json" directive used inside a double-quoted attribute. Blade
 * compiles that directive to json_encode() with the JSON_HEX_QUOT flag,
 * which escapes quotes inside the encoded string but not the string's own
 * delimiting double-quote characters — so encoding a plain string emits
 * those delimiters raw, terminating the attribute exactly like the previous
 * two incidents (beda942 #190, 01fb669 #213) did.
 *
 * It only fires when resumableBatch() is non-null, i.e. only while a batch
 * is actually in flight — matching the reported symptom (broke right after
 * a 360-senior bulk upload redirected to this page mid-batch). This test
 * reproduces exactly that: a batch dispatched but not yet finished, then a
 * fresh GET of the batch page (simulating the bulk-upload redirect / a
 * reload mid-run).
 */
class BatchPageResumeRenderTest extends TestCase
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
    public function batch_page_x_data_attribute_survives_intact_while_a_batch_is_resumable(): void
    {
        Bus::fake();

        // An empty fake batch is enough — Batch::finished() only checks
        // finishedAt (null by default), regardless of job count. This is
        // exactly the "batch dispatched, not yet finished" state
        // resumableBatch() looks for via Bus::findBatch()->finished().
        $batch = Bus::batch([])->name('Test Batch')->dispatch();

        $cacheKey = 'ml_batch_'.now()->format('YmdHis');
        Cache::put("{$cacheKey}:total", 360, now()->addHours(2));
        Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

        $response = $this->actingAs($this->admin)->get(route('ml.batch'));
        $response->assertOk();

        $html = $response->getContent();

        // Extract the run panel's x-data="..." value the same way a
        // browser's HTML parser would: from the attribute start to the FIRST
        // unescaped `"`. Anchored on "running: false," (the run panel's own
        // opening key) so this targets that specific attribute, not e.g. the
        // persisted layout's unrelated x-data="appLayout". If @json leaked a
        // raw quote, that first `"` lands mid-JS instead of at the
        // attribute's real close, and the extracted value will be truncated
        // well short of the closing fmt(s) helper.
        $this->assertMatchesRegularExpression(
            '/x-data="\{\s*running: false,[^"]*"/',
            $html,
            'Could not locate the run panel\'s x-data="..." attribute at all in the response.'
        );
        preg_match('/x-data="(\{\s*running: false,[^"]*)"/', $html, $matches);
        $xData = $matches[1] ?? '';

        $this->assertStringContainsString(
            'fmt(s)',
            $xData,
            'x-data attribute was terminated early — it does not reach the fmt(s) helper defined '
                .'near the end of the intended attribute value. This is the leaked-JS-as-page-text bug: '
                .'a raw quote (e.g. from @json() instead of @js()) closed the attribute prematurely.'
        );
        $this->assertStringContainsString('this.cacheKey', $xData);
        $this->assertStringContainsString($cacheKey, $xData);
    }
}
