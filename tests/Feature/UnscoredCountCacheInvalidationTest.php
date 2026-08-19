<?php

namespace Tests\Feature;

use App\Jobs\ProcessMlBatch;
use App\Jobs\ProcessMlSingle;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the Batch Assessment page's "N senior(s) still
 * awaiting risk assessment" banner staying visible after a batch/single
 * re-run actually finished scoring everyone. MlController::unscoredCount()
 * caches its query for 60s (services.python's batch chunking makes it
 * expensive to run on every 3s poll) — nothing forgot that cache when a
 * job actually changed the count, so the banner could show stale data for
 * up to a minute after the real work was done.
 */
class UnscoredCountCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUnscoredSenior(): array
    {
        $senior = SeniorCitizen::create([
            'osca_id' => 'UNS-'.uniqid(),
            'first_name' => 'Unscored',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);

        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now(),
            'status' => 'processed',
        ]);

        return [$senior, $survey];
    }

    #[Test]
    public function process_ml_batch_forgets_the_unscored_count_cache(): void
    {
        [$senior] = $this->makeUnscoredSenior();

        // Simulate a stale cached value primed before the batch ran.
        Cache::put('ml_unscored_count', 999, 60);

        (new ProcessMlBatch([$senior->id], 'test-cache-key:'.uniqid()))->handle(app(MlService::class));

        $this->assertNull(
            Cache::get('ml_unscored_count'),
            'ProcessMlBatch::handle() must forget ml_unscored_count so the next poll recomputes it.'
        );
    }

    #[Test]
    public function process_ml_single_forgets_the_unscored_count_cache(): void
    {
        [$senior, $survey] = $this->makeUnscoredSenior();

        Cache::put('ml_unscored_count', 999, 60);

        (new ProcessMlSingle($senior->id, $survey->id))->handle(app(MlService::class));

        $this->assertNull(
            Cache::get('ml_unscored_count'),
            'ProcessMlSingle::handle() must forget ml_unscored_count so the next poll recomputes it.'
        );
    }
}
