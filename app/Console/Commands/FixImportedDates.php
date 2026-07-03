<?php

namespace App\Console\Commands;

use App\Models\SeniorCitizen;
use App\Support\DateParser;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-time correction for survey_date / consent_given_at values that were
 * seeded before the DateParser day/month ambiguity fix (e.g. "8/4/2026"
 * misread as August 4 instead of April 8, Philippine d/m/Y locale).
 *
 * Re-reads osca.csv, matches each row to its SeniorCitizen by
 * first_name + last_name + date_of_birth + barangay (same key as
 * seniors:backfill-demographics), and only overwrites a value when the
 * value currently stored matches what the OLD buggy parser would have
 * produced — never overwrites rows that were entered manually or already
 * correct.
 *
 * Usage:
 *   php artisan data:fix-imported-dates --dry-run                 # preview counts only
 *   php artisan data:fix-imported-dates                           # apply to all matched rows
 *   php artisan data:fix-imported-dates --only="Benito Bermudez,Albino Antolin"
 *                                                                  # apply to specific people only
 *   php artisan data:fix-imported-dates --revert                  # undo a previous run
 *   php artisan data:fix-imported-dates --revert --only="..."     # undo for specific people only
 */
class FixImportedDates extends Command
{
    protected $signature = 'data:fix-imported-dates
                            {--dry-run : Preview changes without writing to the database}
                            {--revert : Reverse a previous run, restoring the pre-fix (buggy) values}
                            {--only= : Comma-separated "First Last" names to limit the run to}';

    protected $description = 'Correct survey_date / consent_given_at values that were mis-parsed '
        .'by the pre-fix ambiguous-date bug (osca.csv only, non-destructive, match-verified).';

    /** @var array<string,SeniorCitizen> match-key → loaded model */
    private array $lookup = [];

    private array $stats = [
        'rows' => 0,
        'matched' => 0,
        'unmatched' => 0,
        'survey_date_fixed' => 0,
        'consent_fixed' => 0,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $revert = (bool) $this->option('revert');

        $only = null;
        if ($this->option('only')) {
            $only = collect(explode(',', $this->option('only')))
                ->map(fn ($n) => strtolower(trim($n)))
                ->filter()
                ->values()
                ->all();
        }

        if ($dryRun) {
            $this->info('[DRY RUN] No data will be written to the database.');
        }
        if ($revert) {
            $this->info('[REVERT] Restoring pre-fix (buggy) values.');
        }
        if ($only) {
            $this->info('[SCOPED] Limited to: '.implode(', ', $only));
        }

        $csvPath = file_exists(base_path('osca.csv'))
            ? base_path('osca.csv')
            : base_path('../osca.csv');

        if (! file_exists($csvPath)) {
            $this->error('osca.csv not found. Tried: '.base_path('osca.csv').' and '.base_path('../osca.csv'));

            return self::FAILURE;
        }

        $this->info('Loading seniors from database...');
        SeniorCitizen::with('qolSurveys')->whereNull('deleted_at')->chunk(200, function ($chunk) {
            foreach ($chunk as $senior) {
                $key = $this->matchKey(
                    $senior->first_name,
                    $senior->last_name,
                    $senior->date_of_birth?->format('Y-m-d'),
                    $senior->barangay,
                );
                $this->lookup[$key] = $senior;
            }
        });
        $this->info('  '.count($this->lookup).' seniors loaded.');
        $this->info('');

        $this->info('Processing osca.csv...');
        foreach ($this->parseCsvRows($csvPath) as $row) {
            if ($only && ! in_array(strtolower(trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))), $only, true)) {
                continue;
            }
            $this->stats['rows']++;
            $this->processRow($row, $dryRun, $revert);
        }

        $verb = $revert ? 'reverted' : 'corrected';
        $this->info('');
        $this->info('=== '.($revert ? 'Revert' : 'Fix').' '.($dryRun ? 'DRY RUN ' : '').'Complete ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['CSV rows processed', $this->stats['rows']],
                ['Rows matched to a senior', $this->stats['matched']],
                ['Rows NOT matched (name / DOB / barangay mismatch)', $this->stats['unmatched']],
                ["survey_date {$verb}", $this->stats['survey_date_fixed']],
                ["consent_given_at {$verb}", $this->stats['consent_fixed']],
            ]
        );

        if ($dryRun) {
            $this->line('');
            $this->line('Run without --dry-run to apply these changes.');
        }

        return self::SUCCESS;
    }

    private function processRow(array $row, bool $dryRun, bool $revert = false): void
    {
        $firstName = $row['first_name'] ?? null;
        $lastName = $row['last_name'] ?? null;
        $barangay = $row['barangay'] ?? null;

        if (! $firstName || ! $lastName || ! $barangay) {
            return;
        }

        $dob = DateParser::parse($row['dob'] ?? null, dobMode: true);
        if (! $dob) {
            return;
        }

        $key = $this->matchKey($firstName, $lastName, $dob, $barangay);
        if (! isset($this->lookup[$key])) {
            $this->stats['unmatched']++;

            return;
        }

        $this->stats['matched']++;
        $senior = $this->lookup[$key];

        $timestamp = $row['timestamp'] ?? null;
        $correct = DateParser::parse($timestamp);
        $oldBuggy = $this->oldBuggyParse($timestamp);

        if (! $correct || ! $oldBuggy || $correct === $oldBuggy) {
            return;
        }

        // Forward: current === oldBuggy → write correct.
        // Revert:  current === correct  → write oldBuggy back.
        $expectedBefore = $revert ? $correct : $oldBuggy;
        $writeValue = $revert ? $oldBuggy : $correct;

        $survey = $senior->qolSurveys->first();
        if ($survey && $survey->survey_date?->format('Y-m-d') === $expectedBefore) {
            $this->stats['survey_date_fixed']++;
            if (! $dryRun) {
                $survey->update(['survey_date' => $writeValue]);
            }
        }

        if ($senior->consent_given_at?->format('Y-m-d') === $expectedBefore) {
            $this->stats['consent_fixed']++;
            if (! $dryRun) {
                $senior->consent_given_at = Carbon::parse($writeValue)->setTimeFrom($senior->consent_given_at);
                $senior->save();
            }
        }
    }

    /**
     * Reproduce the pre-fix parser exactly, to confirm a stored value was
     * actually produced by the bug before overwriting it.
     */
    private function oldBuggyParse(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || strtolower(trim($value)) === 'nan') {
            return null;
        }
        $v = trim($value);

        $hasSlashDate = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m);
        if ($hasSlashDate && (int) $m[1] > 12) {
            try {
                return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}")->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        foreach (['m/d/Y H:i', 'm/d/Y', 'Y-m-d', 'd/m/Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $v)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchKey(string $firstName, string $lastName, ?string $dob, string $barangay): string
    {
        return implode("\x00", [
            strtolower(trim($firstName)),
            strtolower(trim($lastName)),
            $dob ?? '',
            strtolower(trim($barangay)),
        ]);
    }

    private function parseCsvRows(string $path): array
    {
        $fp = fopen($path, 'r');
        if (! $fp) {
            $this->error("Cannot open {$path}");

            return [];
        }

        $header = fgetcsv($fp);
        if (! $header) {
            fclose($fp);

            return [];
        }
        if (str_starts_with($header[0], "\xef\xbb\xbf")) {
            $header[0] = substr($header[0], 3);
        }

        $rows = [];
        while (($line = fgetcsv($fp)) !== false) {
            $raw = $this->rowToAssoc($header, $line);
            $rows[] = [
                'first_name' => $this->strVal($raw['first_name'] ?? null),
                'last_name' => $this->strVal($raw['last_name'] ?? null),
                'barangay' => $this->strVal($raw['barangay'] ?? null),
                'dob' => $this->strVal($raw['dob'] ?? null),
                'timestamp' => $this->strVal($raw['timestamp'] ?? null),
            ];
        }
        fclose($fp);

        return $rows;
    }

    private function rowToAssoc(array $header, array $line): array
    {
        $assoc = [];
        foreach ($header as $idx => $key) {
            $assoc[trim((string) $key)] = $line[$idx] ?? null;
        }

        return $assoc;
    }

    private function strVal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return ($v === '' || strtolower($v) === 'nan') ? null : $v;
    }
}
