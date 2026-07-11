<?php

namespace Tests\Feature;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SeniorLocationObserver keeps barangay-derived state in sync when a
 * senior's barangay is edited: it drops the now-stale generated map
 * coordinates, marks the cached ML result stale, bumps the GIS/dashboard
 * cache version, and queues a targeted re-geocode. See the observer's
 * docblock for why osca_id is deliberately left untouched.
 */
class SeniorLocationObserverTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    private function makeMlResult(SeniorCitizen $senior, array $overrides = []): MlResult
    {
        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        return MlResult::create(array_merge([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live_model',
            'overall_risk_level' => 'HIGH',
            'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.41,
            'cluster_named_id' => 4,
            'cluster_name' => 'Low Functioning / Multi-Domain Priority Seniors',
            'scored_at' => now(),
            'processed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function barangay_change_clears_generated_coordinates(): void
    {
        Queue::fake();

        $senior = $this->makeSenior([
            'latitude' => 14.2782,
            'longitude' => 121.4588,
            'location_source' => 'barangay_generalized',
            'location_accuracy' => 'barangay_level',
        ]);

        $senior->update(['barangay' => 'Sabang']);
        $senior->refresh();

        $this->assertNull($senior->latitude);
        $this->assertNull($senior->longitude);
        $this->assertNull($senior->location_source);
        $this->assertNull($senior->location_accuracy);
    }

    #[Test]
    public function barangay_change_preserves_a_verified_pin(): void
    {
        Queue::fake();

        $senior = $this->makeSenior([
            'latitude' => 14.2782,
            'longitude' => 121.4588,
            'location_source' => 'manual_pin',
            'location_accuracy' => 'verified',
        ]);

        $senior->update(['barangay' => 'Sabang']);
        $senior->refresh();

        $this->assertEquals(14.2782, (float) $senior->latitude);
        $this->assertEquals(121.4588, (float) $senior->longitude);
        $this->assertSame('manual_pin', $senior->location_source);
    }

    #[Test]
    public function barangay_change_does_not_reissue_osca_id(): void
    {
        Queue::fake();

        $senior = $this->makeSenior();
        $originalOscaId = $senior->osca_id;

        $senior->update(['barangay' => 'Sabang']);
        $senior->refresh();

        $this->assertSame($originalOscaId, $senior->osca_id);
        $this->assertSame('Sabang', $senior->barangay);
    }

    #[Test]
    public function barangay_change_marks_the_latest_ml_result_stale(): void
    {
        Queue::fake();

        $senior = $this->makeSenior();
        $result = $this->makeMlResult($senior);
        $this->assertNotTrue((bool) $result->is_stale);

        $senior->update(['barangay' => 'Sabang']);
        $result->refresh();

        $this->assertTrue($result->is_stale);
        $this->assertSame('senior_profile_updated', $result->stale_reason);
    }

    #[Test]
    public function barangay_change_bumps_the_cache_version_and_forgets_gis_cache(): void
    {
        Queue::fake();

        $senior = $this->makeSenior();
        // Force a known baseline: the version stamp has second-level
        // granularity, so comparing against whatever a prior test/observer
        // left behind can collide within the same wall-clock second.
        Cache::forget('senior_data.version');
        Cache::put('gis.seniors_geojson.full', 'stale-payload', now()->addMinutes(5));

        $senior->update(['barangay' => 'Sabang']);

        $this->assertGreaterThan(0, SeniorDataVersion::current());
        $this->assertFalse(Cache::has('gis.seniors_geojson.full'));
    }

    #[Test]
    public function barangay_change_queues_a_targeted_regeocode(): void
    {
        Queue::fake();

        $senior = $this->makeSenior();
        $senior->update(['barangay' => 'Sabang']);

        Queue::assertPushed(QueuedCommand::class, fn ($job) => $job->displayName() === 'gis:geocode');
    }

    #[Test]
    public function editing_an_unrelated_field_does_not_touch_location_or_ml_staleness(): void
    {
        Queue::fake();

        $senior = $this->makeSenior([
            'latitude' => 14.2782,
            'longitude' => 121.4588,
            'location_source' => 'barangay_generalized',
            'location_accuracy' => 'barangay_level',
        ]);
        $result = $this->makeMlResult($senior);

        $senior->update(['contact_number' => '09171234567']);
        $senior->refresh();
        $result->refresh();

        $this->assertEquals(14.2782, (float) $senior->latitude);
        $this->assertFalse($result->is_stale);
        Queue::assertNothingPushed();
    }
}
