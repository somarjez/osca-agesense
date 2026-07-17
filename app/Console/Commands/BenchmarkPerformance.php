<?php

namespace App\Console\Commands;

use App\Exports\RegistryExport;
use App\Livewire\Dashboard\MainDashboard;
use App\Models\SeniorCitizen;
use App\Services\ClusterAnalyticsService;
use App\Services\MlService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * osca:benchmark
 *
 * Times the automatable operations against the 36 acceptance-test thresholds
 * and reports PASS/FAIL. Safe by default: every check that runs without a
 * flag is read-only (queries, in-memory PDF/XLSX rendering, a reuse-path ML
 * call). The two heavier checks that genuinely mutate ML data — a forced
 * single recompute and a full batch run — are opt-in via flags, and are no
 * more invasive than the existing `ml:run-single` / `ml:batch-analyze`
 * commands they reuse.
 *
 * UI-render-only criteria (system startup, sign-in, navigation, QoL page,
 * dashboard/GIS page paint, map load) need a real browser and are not
 * measured here — see docs/TESTING_PLAN.md / testing_plan/ for the manual
 * stopwatch instrument that covers those.
 */
class BenchmarkPerformance extends Command
{
    protected $signature = 'osca:benchmark
                            {--batch : Also time a full ml:batch-analyze run (criterion #30). MUTATES data (recomputes stale/missing ml_results) exactly like running that command directly.}
                            {--recompute-senior= : Force-recompute one senior\'s ML pipeline by ID to time a genuine (non-cached) analysis (#21-26/#24). MUTATES that senior\'s ml_results + recommendations.}';

    protected $description = 'Measure server-side operations against the 36 performance thresholds and report PASS/FAIL.';

    /** @var array<int, array{no:int, name:string, target:string, measured:?float, status:string, note:string}> */
    private array $rows = [];

    public function handle(MlService $ml): int
    {
        $this->info('=== OSCA Performance Benchmark ===');
        $this->line('Read-only checks run by default. Pass --batch / --recompute-senior=ID for the two mutating checks.');
        $this->line('');

        // Untimed warm-up: a fresh CLI process pays a one-time cold-start
        // cost on its FIRST outbound HTTP call (curl/TLS-stack init), which
        // the always-warm php-fpm/artisan-serve worker that serves real
        // requests never repeats per-request. Absorb that cost here so the
        // health-check timing below reflects steady-state reality.
        try {
            $ml->healthCheck();
        } catch (\Throwable) {
        }

        $this->benchHealthCheck($ml);
        $this->benchSearch();
        $this->benchFilter();
        $this->benchPagination();
        $this->benchDashboard();
        $this->benchIndividualAnalysis($ml);
        $this->benchPdf();
        $this->benchExport();

        if ($seniorId = $this->option('recompute-senior')) {
            $this->benchForcedRecompute($ml, (int) $seniorId);
        }

        if ($this->option('batch')) {
            $this->benchBatch();
        }

        $this->printReport();

        $failed = collect($this->rows)->contains(fn ($r) => $r['status'] === 'FAIL');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // ── Timing helpers ───────────────────────────────────────────────────────

    /**
     * @return array{0: float, 1: mixed, 2: ?string} [elapsedSeconds, result, errorMessage]
     */
    private function time(callable $fn): array
    {
        $start = microtime(true);
        $result = null;
        $error = null;
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [microtime(true) - $start, $result, $error];
    }

    private function record(int $no, string $name, string $target, float $targetSeconds, ?float $measured, string $note = ''): void
    {
        $status = $measured === null
            ? 'SKIPPED'
            : ($measured <= $targetSeconds ? 'PASS' : 'FAIL');

        $this->rows[] = [
            'no' => $no,
            'name' => $name,
            'target' => $target,
            'measured' => $measured,
            'status' => $status,
            'note' => $note,
        ];
    }

    // ── Checks (read-only) ───────────────────────────────────────────────────

    private function benchHealthCheck(MlService $ml): void
    {
        [$elapsed, , $error] = $this->time(fn () => $ml->healthCheck());
        $this->record(2, 'ML service health check', '≤2s', 2.0, $error ? null : $elapsed, $error ?? '');
    }

    private function benchSearch(): void
    {
        $sample = SeniorCitizen::active()->orderBy('id')->first();
        if (! $sample) {
            $this->record(7, 'Record search', '≤2s', 2.0, null, 'no seniors seeded');

            return;
        }

        $term = mb_substr($sample->last_name, 0, 3);
        [$elapsed, , $error] = $this->time(fn () => SeniorCitizen::active()
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('osca_id', 'like', "%{$term}%"))
            ->paginate(20));
        $this->record(7, 'Record search', '≤2s', 2.0, $error ? null : $elapsed, $error ?? '');
    }

    private function benchFilter(): void
    {
        $barangay = SeniorCitizen::active()->value('barangay');
        [$elapsed, , $error] = $this->time(fn () => SeniorCitizen::active()
            ->when($barangay, fn ($q) => $q->byBarangay($barangay))
            ->byRiskLevel('HIGH')
            ->paginate(20));
        $this->record(8, 'Record filtering (barangay + risk)', '≤2s', 2.0, $error ? null : $elapsed, $error ?? '');
    }

    private function benchPagination(): void
    {
        [$elapsed, , $error] = $this->time(fn () => SeniorCitizen::active()
            ->orderBy('id')
            ->paginate(20, ['*'], 'page', 2));
        $this->record(9, 'Pagination (page 2)', '≤2s', 2.0, $error ? null : $elapsed, $error ?? '');
    }

    private function benchDashboard(): void
    {
        // Instantiating the real Livewire component (not a duplicate query
        // rewrite) and calling render() executes every getStats() /
        // getRiskDistribution() / ... aggregate exactly as production does —
        // PHP evaluates render()'s view() arguments before returning. We
        // deliberately do not render the returned view to a string, since
        // that pulls in Livewire's `@this` hydration lifecycle, which this
        // out-of-request context doesn't provide and doesn't need to.
        [$elapsed, , $error] = $this->time(function () {
            $component = new MainDashboard;
            $component->boot(app(ClusterAnalyticsService::class));

            return $component->render();
        });
        $this->record(
            17,
            'Dashboard aggregate build (cards + chart data)',
            '≤5s',
            5.0,
            $error ? null : $elapsed,
            $error ?? 'measures current cache state; not forced cold'
        );
    }

    private function benchIndividualAnalysis(MlService $ml): void
    {
        $senior = SeniorCitizen::active()
            ->whereHas('latestMlResult')
            ->whereHas('latestQolSurvey')
            ->first();

        if (! $senior || ! $senior->latestQolSurvey) {
            $this->record(24, 'Individual ML analysis (warm reuse-path)', '≤10s', 10.0, null, 'no senior with an existing ML result found');

            return;
        }

        [$elapsed, , $error] = $this->time(fn () => $ml->runPipeline($senior, $senior->latestQolSurvey, force: false));
        $this->record(
            24,
            'Individual ML analysis (warm reuse-path)',
            '≤10s',
            10.0,
            $error ? null : $elapsed,
            $error ?? 'reuse-path timing; pass --recompute-senior=ID for a genuine forced run'
        );
    }

    private function benchPdf(): void
    {
        $senior = SeniorCitizen::active()->whereHas('latestMlResult')->first();
        if (! $senior) {
            $this->record(27, 'Senior citizen PDF report', '≤10s', 10.0, null, 'no seniors seeded');

            return;
        }

        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        // Explicit array, not compact('senior') — compact() reads the calling
        // scope's symbol table, which an arrow function's auto-captured
        // variables don't populate, so compact() would see nothing here.
        [$elapsed, , $error] = $this->time(fn () => Pdf::loadView('seniors.pdf', ['senior' => $senior])
            ->setPaper('a4', 'portrait')
            ->output());
        $this->record(27, 'Senior citizen PDF report', '≤10s', 10.0, $error ? null : $elapsed, $error ?? 'rendered in memory, not saved');
    }

    private function benchExport(): void
    {
        [$elapsed, , $error] = $this->time(fn () => Excel::raw(new RegistryExport, ExcelFormat::XLSX));
        $this->record(28, 'CSV/Excel export (registry XLSX)', '≤10s', 10.0, $error ? null : $elapsed, $error ?? 'generated in memory, not saved');
    }

    // ── Checks (opt-in, mutating) ────────────────────────────────────────────

    private function benchForcedRecompute(MlService $ml, int $seniorId): void
    {
        $senior = SeniorCitizen::find($seniorId);
        $survey = $senior?->latestQolSurvey;

        if (! $senior || ! $survey) {
            $this->record(24, 'Individual ML analysis (forced recompute)', '≤10s (max 15s)', 15.0, null, "senior #{$seniorId} or its QoL survey not found");

            return;
        }

        $this->warn("--recompute-senior={$seniorId}: forcing a real recompute (mutates this senior's ml_results + recommendations)...");
        [$elapsed, , $error] = $this->time(fn () => $ml->runPipeline($senior, $survey, force: true));
        $this->record(24, 'Individual ML analysis (forced recompute)', '≤10s (max 15s)', 15.0, $error ? null : $elapsed, $error ?? '');
    }

    private function benchBatch(): void
    {
        $this->warn('--batch: running ml:batch-analyze for real (recomputes stale/missing ml_results, same as running that command directly).');
        $start = microtime(true);
        Artisan::call('ml:batch-analyze');
        $elapsed = microtime(true) - $start;
        $this->line(Artisan::output());
        $this->record(30, 'Full batch ML processing (~360 records)', '≤5min, no timeout', 300.0, $elapsed);
    }

    // ── Report ───────────────────────────────────────────────────────────────

    private function printReport(): void
    {
        $this->line('');
        $this->info('=== Results ===');
        $this->table(
            ['#', 'Operation', 'Target', 'Measured', 'Status', 'Note'],
            collect($this->rows)->map(fn ($r) => [
                $r['no'],
                $r['name'],
                $r['target'],
                $r['measured'] === null ? '—' : number_format($r['measured'], 3).'s',
                $r['status'],
                $r['note'],
            ])->all()
        );
        $this->line('');
        $this->line('UI-render criteria (system startup, sign-in/out, page navigation, QoL page,');
        $this->line('dashboard/GIS paint, map load) need a real browser and stay manual-stopwatch');
        $this->line('items — see docs/TESTING_PLAN.md / testing_plan/.');
    }
}
