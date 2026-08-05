<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUploadRequest;
use App\Jobs\ProcessMlBatch;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Support\Concerns\DrainsMlQueue;
use App\Support\DateParser;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BulkUploadController extends Controller
{
    use DrainsMlQueue;

    // ── CSV column spec (mirrors OscaCsvSeeder) ───────────────────────────

    private const REQUIRED_COLUMNS = [
        'first_name', 'last_name', 'barangay', 'dob', 'gender',
    ];

    // The 'osca_id' column is the official OSCA-ID (optional, staff-entered) —
    // distinct from the system-generated osca_id column, which every imported
    // row gets automatically via generateOscaId() regardless of this column.

    private const SAMPLE_HEADERS = [
        'first_name', 'middle_name', 'last_name', 'name_ext', 'barangay', 'dob', 'osca_id',
        'contact_number', 'place_of_birth', 'marital_status', 'gender', 'religion',
        'ethnic_origin', 'blood_type', 'num_children', 'num_working_children',
        'child_financial_support', 'spouse_working', 'household_size',
        'education', 'specialization', 'community_service', 'living_with',
        'household_condition', 'income_source', 'real_assets', 'movable_assets',
        'monthly_income_range', 'problems_needs',
        'medical_concern', 'dental_concern', 'optical_concern',
        'hearing_concern', 'social_emotional_concern', 'healthcare_difficulty',
        'has_medical_checkup', 'checkup_schedule',
        'qol_enjoy_life', 'qol_life_satisfaction', 'qol_future_outlook', 'qol_meaningfulness',
        'phy_energy', 'phy_pain_r', 'phy_health_limit_r', 'phy_mobility_outside', 'phy_mobility_indoor',
        'psych_happiness', 'psych_peace', 'psych_lonely_r', 'psych_confidence',
        'func_independence', 'func_autonomy', 'func_control', 'env_income_limit_r',
        'soc_social_support', 'soc_close_friend', 'soc_participation', 'soc_opportunity', 'soc_respect',
        'env_safe_home', 'env_safe_neighborhood', 'env_service_access', 'env_home_comfort',
        'env_fin_household', 'env_fin_medical', 'env_fin_personal',
        'spi_belief_comfort', 'spi_belief_practice',
        'timestamp',
    ];

    // ── Canonical value maps (CSV/Google Form text → UI option text) ─────────

    private const PROBLEMS_NEEDS_MAP = [
        // Income variants
        'Lack of source of income/resources' => 'Lack of income/resources',
        'Lack of source of income' => 'Lack of income/resources',
        'Lack of income' => 'Lack of income/resources',
        'Loss of source of income/resources' => 'Loss of income/resources',
        'Loss of source of income' => 'Loss of income/resources',
        'Loss of income' => 'Loss of income/resources',
        // Casing / wording fixes
        'Livelihood Opportunities' => 'Livelihood opportunities',
        'Health-Related Issues' => 'Health Related Issues',
        'Lack of access to health care services' => 'Lack of access to healthcare services',
        'High cost of medicine' => 'High cost of medicines',
        'Lack of Social Support' => 'Lack of social support',
        // Spelling / wording fixes
        'Limited Mobillity/Transportation' => 'Limited Mobility/Transportation difficulty',
        'Limited Mobility/Transportation' => 'Limited Mobility/Transportation difficulty',
    ];

    private const INCOME_SOURCE_MAP = [
        'Spouse Salary' => 'Spouse salary',
        'Spouse Pension' => 'Spouse pension',
    ];

    private const MEDICAL_CONCERN_MAP = [
        'Arthritis/Gout' => 'Arthritis / Gout',
        'Mental Health Condition (Depression/Anxiety)' => 'Mental Health Condition (Depression / Anxiety)',
        'Tuberculosis(TB)' => 'Tuberculosis (TB)',
        'Chronic Heart Disease' => 'Coronary Heart Disease',
        // Free-text conditions from Google Form → nearest canonical bucket
        'Heart Enlargement' => 'Coronary Heart Disease',
        'Scoliosis' => 'Physical Disability',
        'Prostate' => 'Other Chronic Disease',
        'Cholesterol' => 'Other Chronic Disease',
        'Anlodipin' => 'Other Chronic Disease',
        'Lungs' => 'Other Chronic Disease',
        'Mioma' => 'Other Chronic Disease',
        'Ulcer' => 'Other Chronic Disease',
        'Thyroid' => 'Other Chronic Disease',
        'Nagbabarang Puson' => 'Other Chronic Disease',
        'operated bato sa atay' => 'Other Chronic Disease',
    ];

    private const SOCIAL_EMOTIONAL_CONCERN_MAP = [
        'Feeling/Lonliness/Isolation' => 'Feeling/Loneliness/Isolation',
        'Living in healthy environment' => 'Living in a healthy environment',
        'Lack Social Support' => 'Lack social support',
        'Lack liesure/recreational activites' => 'Lack leisure activities',
        'Lack SC-friendly Environment' => 'Living in a healthy environment',
        // Free-text stress entries
        'Stress' => 'Feeling Depressed/Anxiety',
        'Apo stress' => 'Feeling Depressed/Anxiety',
    ];

    private const HEALTHCARE_DIFFICULTY_MAP = [
        'High cost of medicine' => 'High cost of medicines',
        'Difficulty in accessing health facilities' => 'Difficulty accessing health facilities',
        'Long waiting in health centers' => 'Long waiting time',
    ];

    private const OPTICAL_CONCERN_MAP = [
        'Blurred Vision' => 'Blurred vision',
        'Healthy eyes' => 'Healthy Eyes',
        // Free-text optical conditions from Google Form → nearest canonical bucket
        'Astigmatism' => 'Eye impairment',
        'half-eyed problem' => 'Eye impairment',
        'Ploaters' => 'Eye impairment',   // floaters
        'Eye Stroke' => 'Eye impairment',
        'Pugita Eye' => 'Eye impairment',
        'Malinaw pero malabo mata' => 'Blurred vision',  // Filipino: "clear but blurry eyes"
        'Maintenance patak' => 'Needs eye care',  // maintenance eye drops
        'Affected by diabetes' => 'Needs eye care',
    ];

    private const HEARING_CONCERN_MAP = [
        'Partial Hearing Loss' => 'Partial hearing loss',
        'Difficulty hearing converstaions' => 'Difficulty hearing conversations',
        'Hearing Impairment' => 'Hearing impairment',  // case fix
        'Needs hearing aid' => 'Uses hearing aid',
    ];

    private const PROBLEMS_NEEDS_EXTRA_MAP = [
        // Free-text entry preserved as custom "Others" value
        'Herbal' => 'Others: Herbal',
    ];

    // ── Sample CSV download ───────────────────────────────────────────────

    public function sample()
    {
        $rows = [
            self::SAMPLE_HEADERS,
            [
                'Juan', 'D.', 'Santos', 'Jr.', 'Pinagsanjan', '01/15/1948', '',
                '09123456789', 'Pagsanjan, Laguna', 'Widowed', 'Male', 'Catholic',
                '', 'A+', '3', '1', 'Yes', 'Deceased', '4',
                'Elementary Graduate', '', 'Social Work', 'Spouse,Children',
                'Owned House', 'Pension,Remittances', '', '',
                'Below 5,000', 'Financial difficulties',
                'Hypertension,Arthritis', '', '', '', 'Anxiety', 'Mobility',
                'Yes', 'Monthly',
                '4', '3', '3', '4',
                '3', '3', '3', '3', '2',
                '3', '3', '3', '3',
                '3', '3', '3', '3',
                '4', '4', '3', '3', '4',
                '3', '2', '3', '3',
                '3', '3', '3',
                '4', '4',
                '01/10/2024',
            ],
        ];

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="bulk_upload_template.csv"',
        ]);
    }

    // ── Upload + import ───────────────────────────────────────────────────

    public function upload(BulkUploadRequest $request)
    {
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        // Parse rows into a uniform array-of-arrays
        try {
            if (in_array($ext, ['xlsx', 'xls'])) {
                $rows = $this->parseExcel($file->getRealPath());
            } else {
                $rows = $this->parseCsv($file->getRealPath());
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Could not parse file: '.$e->getMessage()]);
        }

        if (count($rows) < 2) {
            return back()->withErrors(['file' => 'The file has no data rows.']);
        }

        $header = array_map('trim', $rows[0]);

        // Strip UTF-8 BOM from first column header (Google Sheets / Excel export artefact)
        if ($header && str_starts_with($header[0], "\xef\xbb\xbf")) {
            $header[0] = substr($header[0], 3);
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing) {
            return back()->withErrors([
                'file' => 'Missing required columns: '.implode(', ', $missing).'. Download the sample template to see the expected format.',
            ]);
        }

        $dataRows = array_slice($rows, 1, null, true);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        // ── Pre-pass: batch the lookups that used to run as separate queries
        // INSIDE the per-row loop (duplicate-senior check, duplicate-OSCA-ID
        // check, osca_id sequence generation via generateOscaId()'s MAX(...))
        // — at 10k rows that was ~3-4 queries per row, ~30k-40k total, all
        // inside one long-held transaction. Collect what the file actually
        // touches once, up front, then check membership in PHP instead.
        $candidateBarangays = [];
        $existingKeys = [];
        $existingOscaIds = [];

        foreach ($dataRows as $line) {
            $row = $this->rowToAssoc($header, $line);
            if (empty(array_filter(array_map('trim', $line)))) {
                continue;
            }
            $barangay = $this->strVal($row['barangay'] ?? null);
            if ($barangay) {
                $candidateBarangays[] = $barangay;
            }
        }
        $candidateBarangays = array_values(array_unique($candidateBarangays));

        if ($candidateBarangays) {
            SeniorCitizen::select('first_name', 'last_name', 'date_of_birth', 'barangay')
                ->whereIn('barangay', $candidateBarangays)
                ->get()
                ->each(function ($s) use (&$existingKeys) {
                    $dob = $s->date_of_birth instanceof CarbonInterface
                        ? $s->date_of_birth->format('Y-m-d')
                        : (string) $s->date_of_birth;
                    $existingKeys[strtolower("{$s->first_name}|{$s->last_name}|{$dob}|{$s->barangay}")] = true;
                });

            // withTrashed(): official_osca_id is unique at the DB level regardless of
            // soft-delete status (archived rows still occupy the value), so this
            // check must see them too — otherwise a staff-entered ID that matches an
            // archived senior would pass here only for the DB to reject the insert.
            $existingOscaIds = SeniorCitizen::withTrashed()
                ->whereNotNull('official_osca_id')
                ->pluck('official_osca_id')
                ->map(fn ($v) => strtolower((string) $v))
                ->flip()
                ->toArray();
        }

        // One MAX(...) query per distinct barangay-prefix present in the file
        // (typically a handful) instead of one per row.
        $seqCounters = SeniorCitizen::oscaIdMaxSequences($candidateBarangays);
        $importYear = now()->format('Y');

        $pairs = [];
        $usedOscaIds = [];

        // Chunked commits: a 10k-row file used to sit in one open transaction
        // for the whole import (long lock hold, all-or-nothing on any single
        // bad row). Committing every 500 rows bounds that, at the cost of a
        // changed failure mode — a chunk that errors rolls back only its own
        // rows; earlier successfully-committed chunks are kept, and the import
        // continues with the next chunk rather than discarding everything.
        foreach (array_chunk($dataRows, 500, true) as $chunk) {
            try {
                $chunkResult = DB::transaction(function () use (
                    $chunk, $header, &$errors, &$usedOscaIds, &$existingKeys,
                    &$existingOscaIds, &$seqCounters, $importYear
                ) {
                    $chunkInserted = 0;
                    $chunkSkipped = 0;
                    $chunkPairs = [];

                    foreach ($chunk as $lineNum => $line) {
                        $row = $this->rowToAssoc($header, $line);

                        // Skip blank rows
                        if (empty(array_filter(array_map('trim', $line)))) {
                            continue;
                        }

                        // Required field check
                        $firstName = $this->strVal($row['first_name'] ?? null);
                        $lastName = $this->strVal($row['last_name'] ?? null);
                        $barangay = $this->strVal($row['barangay'] ?? null);
                        $dob = DateParser::parse($row['dob'] ?? null, dobMode: true);

                        if (! $firstName || ! $lastName || ! $barangay || ! $dob) {
                            $chunkSkipped++;
                            $errors[] = 'Row '.($lineNum + 2).': missing required field(s) — skipped.';

                            continue;
                        }

                        // Duplicate guard: skip if an active (non-archived) senior with the
                        // same name, date of birth, and barangay already exists.
                        $dupKey = strtolower("{$firstName}|{$lastName}|{$dob}|{$barangay}");
                        if (isset($existingKeys[$dupKey])) {
                            $chunkSkipped++;
                            $errors[] = 'Row '.($lineNum + 2).": {$firstName} {$lastName} ({$barangay}, {$dob}) already exists — skipped.";

                            continue;
                        }

                        // Optional staff-entered OSCA ID — never blocks the row on
                        // conflict, since it's supplementary to the required, always
                        // auto-generated osca_id. A duplicate (against the DB or an
                        // earlier row in this same file) is dropped with a warning
                        // rather than failing the whole import.
                        $officialOscaId = $this->strVal($row['osca_id'] ?? null);
                        if ($officialOscaId !== null) {
                            $oscaIdKey = strtolower($officialOscaId);
                            $isDuplicate = isset($usedOscaIds[$oscaIdKey]) || isset($existingOscaIds[$oscaIdKey]);

                            if ($isDuplicate) {
                                $errors[] = 'Row '.($lineNum + 2).": OSCA ID '{$officialOscaId}' is already in use — imported without it.";
                                $officialOscaId = null;
                            } else {
                                $usedOscaIds[$oscaIdKey] = true;
                            }
                        }

                        // Same format/collision rule as SeniorCitizen::generateOscaId(),
                        // but the max-sequence lookup already happened once above —
                        // here we just increment the in-memory counter per prefix.
                        $prefix = SeniorCitizen::oscaIdPrefix($barangay);
                        $seqCounters[$prefix] = ($seqCounters[$prefix] ?? 0) + 1;
                        $oscaId = $prefix.'-'.$importYear.'-'.str_pad((string) $seqCounters[$prefix], 4, '0', STR_PAD_LEFT);
                        $existingKeys[$dupKey] = true; // guard against duplicate rows within the same file

                        $senior = SeniorCitizen::create([
                            'osca_id' => $oscaId,
                            'official_osca_id' => $officialOscaId,
                            'first_name' => $firstName,
                            'middle_name' => $this->strVal($row['middle_name'] ?? null),
                            'last_name' => $lastName,
                            'name_extension' => $this->strVal($row['name_ext'] ?? null),
                            'barangay' => $barangay,
                            'date_of_birth' => $dob,
                            'contact_number' => $this->strVal($row['contact_number'] ?? null),
                            'place_of_birth' => $this->strVal($row['place_of_birth'] ?? null),
                            'marital_status' => $this->enumOrNull($row['marital_status'] ?? null, ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Annulled']),
                            'gender' => $this->enumOrNull($row['gender'] ?? null, ['Male', 'Female', 'Prefer not to say']),
                            'religion' => $this->strVal($row['religion'] ?? null),
                            'ethnic_origin' => $this->strVal($row['ethnic_origin'] ?? null),
                            'blood_type' => $this->strVal($row['blood_type'] ?? null),
                            'num_children' => $this->intVal($row['num_children'] ?? null),
                            'num_working_children' => $this->intVal($row['num_working_children'] ?? null),
                            'child_financial_support' => $this->enumOrNull($row['child_financial_support'] ?? null, ['Yes', 'No', 'Occasional', 'N/A']),
                            'spouse_working' => $this->enumOrNull($row['spouse_working'] ?? null, ['Yes', 'No', 'Deceased', 'N/A']),
                            'household_size' => max(1, $this->intVal($row['household_size'] ?? null, 1)),
                            'educational_attainment' => $this->strVal($row['education'] ?? null),
                            'specialization' => $this->toList($row['specialization'] ?? null),
                            'community_service' => $this->toList($row['community_service'] ?? null),
                            'living_with' => $this->toList($row['living_with'] ?? null),
                            'household_condition' => $this->toList($row['household_condition'] ?? null),
                            'income_source' => $this->normalizeList($this->toList($row['income_source'] ?? null), self::INCOME_SOURCE_MAP),
                            'real_assets' => $this->toList($row['real_assets'] ?? null),
                            'movable_assets' => $this->toList($row['movable_assets'] ?? null),
                            'monthly_income_range' => $this->normalizeIncomeRange($row['monthly_income_range'] ?? null),
                            'problems_needs' => $this->normalizeList($this->normalizeList($this->toList($row['problems_needs'] ?? null), self::PROBLEMS_NEEDS_MAP), self::PROBLEMS_NEEDS_EXTRA_MAP),
                            'medical_concern' => $this->normalizeList($this->toList($row['medical_concern'] ?? null), self::MEDICAL_CONCERN_MAP),
                            'dental_concern' => $this->toList($row['dental_concern'] ?? null),
                            'optical_concern' => $this->normalizeList($this->toList($row['optical_concern'] ?? null), self::OPTICAL_CONCERN_MAP),
                            'hearing_concern' => $this->normalizeList($this->toList($row['hearing_concern'] ?? null), self::HEARING_CONCERN_MAP),
                            'social_emotional_concern' => $this->normalizeList($this->toList($row['social_emotional_concern'] ?? null), self::SOCIAL_EMOTIONAL_CONCERN_MAP),
                            'healthcare_difficulty' => $this->normalizeList($this->toList($row['healthcare_difficulty'] ?? null), self::HEALTHCARE_DIFFICULTY_MAP),
                            'has_medical_checkup' => $this->boolVal($row['has_medical_checkup'] ?? null),
                            'checkup_schedule' => $this->strVal($row['checkup_schedule'] ?? null),
                            'status' => 'active',
                            'encoded_by' => 'Bulk Upload',
                        ]);

                        $surveyDate = DateParser::parse($row['timestamp'] ?? null) ?? now()->format('Y-m-d');
                        $survey = QolSurvey::create([
                            'senior_citizen_id' => $senior->id,
                            'survey_version' => 'v1',
                            'survey_date' => $surveyDate,
                            'a1_enjoy_life' => $this->scoreVal($row['qol_enjoy_life'] ?? null),
                            'a2_life_satisfaction' => $this->scoreVal($row['qol_life_satisfaction'] ?? null),
                            'a3_future_outlook' => $this->scoreVal($row['qol_future_outlook'] ?? null),
                            'a4_meaningfulness' => $this->scoreVal($row['qol_meaningfulness'] ?? null),
                            'b1_physical_energy' => $this->scoreVal($row['phy_energy'] ?? null),
                            'b2_pain_discomfort' => $this->scoreVal($row['phy_pain_r'] ?? null),
                            'b3_health_self_care' => $this->scoreVal($row['phy_health_limit_r'] ?? null),
                            'b4_health_outside' => $this->scoreVal($row['phy_mobility_outside'] ?? null),
                            'b5_mobility' => $this->scoreVal($row['phy_mobility_indoor'] ?? null),
                            'c1_happiness' => $this->scoreVal($row['psych_happiness'] ?? null),
                            'c2_calm_peace' => $this->scoreVal($row['psych_peace'] ?? null),
                            'c3_loneliness' => $this->scoreVal($row['psych_lonely_r'] ?? null),
                            'c4_confidence' => $this->scoreVal($row['psych_confidence'] ?? null),
                            'd1_independence' => $this->scoreVal($row['func_independence'] ?? null),
                            'd2_time_control' => $this->scoreVal($row['func_autonomy'] ?? null),
                            'd3_life_control' => $this->scoreVal($row['func_control'] ?? null),
                            'd4_income_limits' => $this->scoreVal($row['env_income_limit_r'] ?? null),
                            'e1_social_support' => $this->scoreVal($row['soc_social_support'] ?? null),
                            'e2_close_person' => $this->scoreVal($row['soc_close_friend'] ?? null),
                            'e3_community_opportunities' => $this->scoreVal($row['soc_participation'] ?? null),
                            'e4_participation' => $this->scoreVal($row['soc_opportunity'] ?? null),
                            'e5_respect' => $this->scoreVal($row['soc_respect'] ?? null),
                            'f1_home_safety' => $this->scoreVal($row['env_safe_home'] ?? null),
                            'f2_neighborhood_safety' => $this->scoreVal($row['env_safe_neighborhood'] ?? null),
                            'f3_service_access' => $this->scoreVal($row['env_service_access'] ?? null),
                            'f4_home_comfort' => $this->scoreVal($row['env_home_comfort'] ?? null),
                            'g1_household_expenses' => $this->scoreVal($row['env_fin_household'] ?? null),
                            'g2_medical_afford' => $this->scoreVal($row['env_fin_medical'] ?? null),
                            'g3_personal_wants' => $this->scoreVal($row['env_fin_personal'] ?? null),
                            'h1_belief_comfort' => $this->scoreVal($row['spi_belief_comfort'] ?? null),
                            'h2_belief_practice' => $this->scoreVal($row['spi_belief_practice'] ?? null),
                            'status' => 'submitted',
                        ]);

                        $survey->computeScores();
                        $chunkPairs[] = ['senior' => $senior, 'survey' => $survey];
                        $chunkInserted++;
                    }

                    return ['inserted' => $chunkInserted, 'skipped' => $chunkSkipped, 'pairs' => $chunkPairs];
                });

                // Only merge into the running totals once the chunk's transaction
                // has actually committed — mutating these inside the closure
                // itself would drift out of sync with the DB on a mid-chunk
                // rollback (PHP variable writes aren't undone by a DB rollback).
                $inserted += $chunkResult['inserted'];
                $skipped += $chunkResult['skipped'];
                $pairs = array_merge($pairs, $chunkResult['pairs']);
            } catch (\Throwable $e) {
                $errors[] = 'A batch of rows failed to import and was rolled back: '.$e->getMessage();
                Log::warning('Bulk upload chunk failed', ['error' => $e->getMessage()]);
            }
        }

        // Queue ML analysis for the inserted seniors — mirrors
        // MlController::batchRun() (chunked Bus::batch() of ProcessMlBatch,
        // same cache-key progress counters the Batch Assessment page reads).
        // This used to call MlService::runBatchPipeline() directly, which
        // held this request open for however long it took to score every
        // imported senior — fine for a handful of rows, but a large import
        // risked a web-server/proxy timeout and a stuck-feeling upload
        // page. Dispatching returns immediately; scoring happens in the
        // background on the queue workers, same as a manual batch run.
        $mlWarning = null;
        if ($pairs) {
            try {
                $seniorIds = array_map(fn ($pair) => $pair['senior']->id, $pairs);

                // Same reload-survival marker as MlController::runSingle()/
                // batchRun() — see the migration/model comments. Set before
                // dispatch so the imported profiles show "still processing"
                // rather than looking untouched if staff check back before a
                // cron tick has drained the queue.
                SeniorCitizen::whereIn('id', $seniorIds)->update(['ml_queued_at' => now()]);

                $cacheKey = 'ml_batch_'.now()->format('YmdHis');
                $chunks = array_chunk($seniorIds, 100);
                $jobs = array_map(fn ($chunk) => new ProcessMlBatch($chunk, $cacheKey), $chunks);

                $batch = Bus::batch($jobs)
                    ->name('ML Batch — Bulk Upload — '.now()->format('Y-m-d H:i'))
                    ->allowFailures()
                    ->dispatch();

                Cache::put("{$cacheKey}:batch_id", $batch->id, now()->addHours(2));
                Cache::put("{$cacheKey}:total", count($seniorIds), now()->addHours(2));
                Cache::put("{$cacheKey}:processed", 0, now()->addHours(2));
                Cache::put("{$cacheKey}:failed", 0, now()->addHours(2));
                Cache::put('ml_last_batch_started', now(), now()->addDays(90));
                Cache::put('ml_last_batch_senior_count', count($seniorIds), now()->addDays(90));

                // Same resumable-batch marker MlController::batchRun() writes —
                // lets the Batch Assessment page pick this run up and resume
                // polling it, since bulk upload now sends staff there instead
                // of back to seniors.index (see below).
                Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

                // Unlike a manual Batch Assessment run, nothing else was going to
                // touch this queue until the next cron tick (up to ~10 min away)
                // — see drainQueueAfterResponse(). A larger budget than
                // MlController::batchRun()'s 60s since an import can seed many
                // more chunks at once; leftovers still fall through to the tick.
                $this->drainQueueAfterResponse(150);
            } catch (\Throwable $mlEx) {
                // Dispatch failure does not block the import — seniors are saved.
                // Surface a warning so staff knows to run batch analysis manually.
                $mlWarning = 'Could not queue ML analysis for imported seniors ('.$mlEx->getMessage().'). Run Batch Assessment manually to generate risk scores.';
                Log::warning('Bulk upload ML batch dispatch failed', ['error' => $mlEx->getMessage()]);
            }
        }

        $msg = "Imported {$inserted} senior(s) successfully.";
        if ($skipped) {
            $msg .= " Skipped {$skipped} row(s) with missing required fields.";
        }

        if ($mlWarning) {
            $errors[] = $mlWarning;
        }

        // When ML jobs were actually queued, send staff to the Batch
        // Assessment page instead of back to the senior list: its 3s poll
        // (MlController::batchStatus()) is what triggers the throttled
        // top-up queue drains, and ml_current_batch (set above) lets the
        // page pick this run up immediately rather than showing the idle
        // "Run Full Batch" button while scoring is already in progress.
        if ($pairs && ! $mlWarning) {
            $msg .= ' Risk assessment is running in the background — see progress below.';

            return redirect()->route('ml.batch')->with('bulk_success', $msg)
                ->with('bulk_errors', $errors);
        }

        return redirect()->route('seniors.index')->with('bulk_success', $msg)
            ->with('bulk_errors', $errors);
    }

    // ── Parsers ───────────────────────────────────────────────────────────

    private function parseCsv(string $path): array
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(null);
        $rows = [];
        foreach ($csv->getRecords() as $record) {
            $rows[] = array_values($record);
        }

        return $rows;
    }

    private function parseExcel(string $path): array
    {
        // Use PhpSpreadsheet if available, otherwise reject
        if (! class_exists(IOFactory::class)) {
            throw new \RuntimeException('Excel support requires phpoffice/phpspreadsheet. Upload a CSV instead.');
        }
        $reader = IOFactory::createReaderForFile($path);
        // Cell values only — skip loading styles/formatting/comments, which we
        // never read here but which otherwise dominate memory on a large sheet.
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $rows[] = $row;
        }

        return array_filter($rows, fn ($r) => array_filter($r));
    }

    // ── Value helpers (mirrors OscaCsvSeeder) ────────────────────────────

    private function rowToAssoc(array $header, array $line): array
    {
        $assoc = [];
        foreach ($header as $idx => $key) {
            $assoc[$key] = $line[$idx] ?? null;
        }

        return $assoc;
    }

    private function strVal($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return ($v === '' || strtolower($v) === 'nan') ? null : $v;
    }

    private function intVal($value, int $default = 0): int
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'nan') {
            return $default;
        }

        return (int) round((float) $value);
    }

    private function boolVal($value): bool
    {
        $v = strtolower((string) ($this->strVal($value) ?? ''));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    private function enumOrNull($value, array $allowed): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }
        foreach ($allowed as $opt) {
            if (strcasecmp($opt, $v) === 0) {
                return $opt;
            }
        }

        return null;
    }

    private function toList($value): array
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return [];
        }
        $parts = array_map('trim', explode(',', $v));

        return array_values(array_filter($parts, fn ($x) => $x !== ''));
    }

    private function scoreVal($value): ?int
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'nan') {
            return null;
        }

        return max(1, min(5, (int) round((float) $value)));
    }

    private function normalizeList(array $items, array $map): array
    {
        return array_values(array_map(fn ($item) => $map[$item] ?? $item, $items));
    }

    private function normalizeIncomeRange($value): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }

        return $v === '60, 000 and above' ? '60,000 and above' : $v;
    }
}
