@extends('layouts.app')
@section('page-title', 'Batch Health Assessment')
@section('page-subtitle', 'Run profile grouping and risk scoring for all eligible seniors')

@section('content')
<div class="space-y-5">

    {{-- Bulk upload redirects here when it queued ML jobs — see BulkUploadController::upload() --}}
    <x-bulk-upload-flash />

    {{-- One-time "keep this page open" guidance right after a bulk upload lands
         here with jobs still running. This isn't just a courtesy tip: this page's
         3s status poll (batchStatus()) is what actually drives the queue drain
         (see DrainsMlQueue) — while the tab stays open, drains run back-to-back;
         closed, scoring falls back to a 10-minute cron tick. Own small x-data
         wrapper, not folded into the big Alpine block below — that one is guarded
         by BladeAlpineAttributeIntegrityTest/BatchPageResumeRenderTest after two
         prior stray-quote regressions (#190, #213), so unrelated state stays out
         of it. Shown at most once per batch via a sessionStorage flag scoped to
         the batch id, so a mid-batch reload doesn't repeat it. --}}
    @if (session('bulk_success') && $resumeBatch)
    <div x-data="{
            guideOpen: false,
            init() {
                const key = 'oscaBatchStayHint:{{ $resumeBatch['batch_id'] }}';
                if (sessionStorage.getItem(key) === '1') return;
                sessionStorage.setItem(key, '1');
                this.guideOpen = true;
            }
         }">
        <x-modal show="guideOpen" max-width="max-w-md" ariaLabel="Keep this page open while assessment runs" :closeable="true">
            <div class="flex flex-col items-center text-center">
                <div class="w-12 h-12 rounded-2xl grid place-items-center bg-info-100 dark:bg-info-700/20 text-info-700 dark:text-info-100">
                    <x-heroicon-o-bolt class="w-6 h-6" aria-hidden="true" />
                </div>
                <h2 class="card-title mt-3.5 mb-1.5">Keep this page open while assessment runs</h2>
                <div class="w-full text-[13px] text-ink-600 dark:text-[#9aada5] leading-relaxed space-y-2">
                    <p>
                        Risk assessment has started for the seniors you just imported. While this page stays
                        open, it checks progress every few seconds, and each check nudges the queue along — so
                        results finish much faster.
                    </p>
                    <p>
                        Leaving is safe and nothing is lost, but without this page open, scoring only advances
                        in roughly 10-minute steps.
                    </p>
                </div>
            </div>
            <x-slot:footer>
                <button type="button" @click="guideOpen = false" class="btn btn-primary flex-1 justify-center">Got it — I&rsquo;ll stay</button>
            </x-slot:footer>
        </x-modal>
    </div>
    @endif

    {{-- Run panel --}}
    <div x-data="{
            running: false,
            done: false,
            showConfirm: false,
            errMsg: '',
            resultMsg: '',
            fallbackMsg: '',
            elapsed: 0,
            timer: null,
            pollTimer: null,
            processed: 0,
            failed: 0,
            fallbackCount: 0,
            coldStartLikely: false,
            total: {{ $totalEligible }},
            progress: 0,
            // Seeded from a live DB count (MlController::unscoredCount()) so
            // it's accurate even on first load, then kept fresh by each
            // poll() response while a batch runs.
            unscored: {{ $unscoredCount }},
            csrfToken: '{{ csrf_token() }}',
            batchUrl: '{{ route('ml.batch.run') }}',
            statusUrl: '{{ route('ml.batch.status') }}',
            wakeStatusUrl: '{{ route('ml.wake-status') }}',
            cacheKey: '',
            batchId: '',
            init() {
                // poll() below has no bound (unlike the senior-profile pollers,
                // which cap at pollMax) — it runs until the batch finishes or is
                // cancelled, which can be many minutes. Without this, navigating
                // away mid-run left it running on a detached component, and its
                // unconditional location.reload() fired on whatever page the
                // user had since moved to, arbitrarily late.
                document.addEventListener('livewire:navigating', () => {
                    clearInterval(this.timer);
                    clearInterval(this.pollTimer);
                });
                @if ($resumeBatch)
                // A batch was already in flight when this page loaded — e.g.
                // bulk upload just redirected here, or staff reopened/reloaded
                // this tab mid-run. Resume the same 3s poll a fresh start()
                // would have, rather than showing the idle Run Full Batch
                // button while scoring is actually still happening.
                //
                // Use the Blade @@js directive here, NOT @@json: @@json compiles
                // to json_encode() with JSON_HEX_QUOT, which escapes quotes
                // inside the string but not the string's own delimiting
                // double-quote characters — so it emits those delimiters raw,
                // which terminates this double-quoted x-data attribute early
                // and dumps the rest of this script as visible page text.
                // Same bug class fixed twice before in this file (beda942
                // #190, 01fb669 #213) via stray-quote-in-comment; this is the
                // same symptom from a runtime-injected quote instead, which
                // the source-scanning regression test can't catch — see
                // BatchPageResumeRenderTest. @@js emits a single-quoted,
                // fully-escaped JS literal instead and is safe here. (This
                // comment writes both directive names with a doubled leading
                // at-sign, Blade's own escape syntax for a literal at-sign,
                // so Blade doesn't try to compile them as real directives —
                // and deliberately contains no double-quote character at all,
                // for the same reason.)
                this.cacheKey = @js($resumeBatch['cache_key']);
                this.batchId  = @js($resumeBatch['batch_id']);
                this.total    = @js($resumeBatch['total']);
                this.running  = true;
                this.timer = setInterval(() => this.elapsed++, 1000);
                this.poll();
                @endif
            },
            async start() {
                if (this.running) return;   // guard against double-run (rapid confirm clicks)
                this.showConfirm = false;
                this.running = true;        // set BEFORE the pre-flight await so a second click while it's pending is also blocked
                this.done = false;
                this.errMsg = ''; this.resultMsg = ''; this.fallbackMsg = '';
                this.processed = 0; this.failed = 0; this.progress = 0;
                this.fallbackCount = 0; this.coldStartLikely = false;

                // Fresh pre-flight check — opens the shared Wake Up Services
                // modal (ml-service-guard.js) if the ML services are down,
                // and waits for either a verified wake or Dismiss. Unlike
                // the wakeStatusUrl fetch below (kept only for the
                // in-progress still-working hint text), this ACTUALLY
                // stops the batch from starting against a dead service.
                const ready = await window.OSCA.requireMl();
                if (!ready) { this.running = false; return; } // user dismissed — batch does not run

                this.elapsed = 0;
                this.timer = setInterval(() => this.elapsed++, 1000);
                // Best-effort, doesn't block start() — coldStartLikely only
                // feeds the this-can-take-a-while hint text below while the
                // batch runs; requireMl() above already confirmed current
                // health, so this will almost always resolve false.
                fetch(this.wakeStatusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => { if (d.mode !== 'http') this.coldStartLikely = true; })
                    .catch(() => {});
                fetch(this.batchUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' }
                })
                .then(r => r.json())
                .then(d => {
                    if (d.error) {
                        clearInterval(this.timer);
                        this.running = false;
                        this.errMsg = d.error;
                        return;
                    }
                    this.cacheKey = d.cache_key;
                    this.batchId  = d.batch_id;
                    this.total    = d.total;
                    this.poll();
                })
                .catch(() => {
                    clearInterval(this.timer);
                    this.running = false;
                    this.errMsg = 'Request failed. Check server logs.';
                });
            },
            poll() {
                this.pollTimer = setInterval(() => {
                    fetch(`${this.statusUrl}?cache_key=${this.cacheKey}&batch_id=${this.batchId}`, {
                        headers: { 'Accept': 'application/json' }
                    })
                    .then(r => r.json())
                    .then(d => {
                        this.processed = d.processed;
                        this.failed    = d.failed;
                        this.progress  = d.progress;
                        this.unscored  = d.unscored;

                        if (d.finished) {
                            clearInterval(this.pollTimer);
                            clearInterval(this.timer);
                            this.running = false;
                            this.done    = true;
                            this.fallbackCount = d.fallback || 0;
                            this.resultMsg = `Batch complete. Processed: ${d.processed}. Failed: ${d.failed}.`;
                            if (d.failed > 0) this.errMsg = `${d.failed} senior(s) failed. Check the queue failed_jobs table.`;
                            // Fallback rows were scored by a crude PHP heuristic, not the
                            // real model (ML services were unreachable for that chunk) —
                            // surfaced separately from resultMsg/errMsg so it isn't lost
                            // as a quiet suffix on an otherwise-green complete banner.
                            if (this.fallbackCount > 0) {
                                this.fallbackMsg = `${this.fallbackCount} senior(s) were scored using a fallback `
                                    + `heuristic, not the ML model (services were unreachable). Re-run Batch `
                                    + `Assessment once ML services are back up to replace these with real scores.`;
                            }
                            // Soft SPA refresh instead of a hard reload — re-fetches this
                            // same page's fresh Last-run summary without throwing away
                            // the persisted sidebar/topbar or forcing a full asset reload.
                            setTimeout(() => Livewire.navigate(window.location.href), 2000);
                        } else if (d.cancelled) {
                            clearInterval(this.pollTimer);
                            clearInterval(this.timer);
                            this.running = false;
                            this.done    = true;
                            this.resultMsg = 'Batch was cancelled.';
                            setTimeout(() => Livewire.navigate(window.location.href), 2000);
                        }
                    })
                    .catch(() => {});
                }, 3000);
            },
            fmt(s) {
                const m = Math.floor(s / 60), sec = s % 60;
                return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
            }
        }"
         class="card">
        <div class="card-body flex items-start gap-5">

            {{-- Idle / running indicator --}}
            <div class="w-8 h-8 flex items-center justify-center flex-shrink-0 mt-0.5">
                <svg x-show="!running"
                     xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <svg x-show="running" x-cloak
                     class="animate-spin h-5 w-5 text-forest-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </div>

            <div class="flex-1 min-w-0">
                <p class="font-semibold text-ink-900">Run Full Batch Assessment</p>
                <p class="text-sm text-ink-500 mb-3">Assesses all seniors with a QoL survey: prepares data → assigns profile group → scores risk → generates recommendations.</p>
                <p class="text-xs text-ink-400 mt-1 mb-3">
                    Last run:
                    @if ($lastBatchRun)
                        {{ $lastBatchRun->format('d M Y, g:i A') }}
                        &middot; {{ $lastBatchCount }} senior(s)
                    @else
                        <span class="text-ink-300">Never run on this machine</span>
                    @endif
                </p>

                {{-- Reflects seniors left unscored right now — including ones stranded by
                     a previous run that outlived its queue-drain time budget (a large bulk
                     upload can take several sweeper cycles to fully clear; see
                     ml:requeue-unscored). Hidden at zero so a fully-caught-up system doesn't
                     show a permanent "0 awaiting" line. --}}
                <p x-show="unscored > 0" x-cloak
                   class="text-xs text-moderate-700 bg-moderate-50 border border-moderate-100 dark:text-[#d4a830] dark:bg-moderate-50/10 dark:border-moderate-700/30 rounded-lg px-2.5 py-1.5 mb-3 inline-block">
                    <span x-text="unscored"></span> senior(s) still awaiting risk assessment.
                </p>

                {{-- Result / error banners --}}
                <p x-show="resultMsg && !running" x-text="resultMsg"
                   :class="errMsg ? 'text-critical-700 bg-critical-50 border border-critical-100' : 'text-low-700 bg-low-50 border border-low-100'"
                   class="text-sm px-3 py-2 rounded-lg mb-2" x-cloak></p>
                <p x-show="errMsg && !running" x-text="errMsg"
                   class="text-xs text-critical-700" x-cloak></p>

                {{-- Fallback warning — separate from resultMsg/errMsg so it can't get
                     lost as a quiet suffix on an otherwise-green "complete" banner when
                     nothing technically "failed" (see ml.batch.status/prediction_source). --}}
                <p x-show="fallbackMsg && !running" x-text="fallbackMsg" x-cloak
                   class="text-sm px-3 py-2 rounded-lg mb-2 text-moderate-700 bg-moderate-50 border border-moderate-100"></p>

                {{-- Progress bar while running --}}
                <div x-show="running" class="space-y-1.5" x-cloak>
                    <div class="flex items-center justify-between text-sm text-forest-700 font-medium">
                        <span x-text="`Processing ${total} seniors… ${processed} done`"></span>
                        <span x-text="fmt(elapsed)" class="text-ink-400 font-normal text-xs"></span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill transition-all duration-500 bg-forest-600"
                             :style="`width:${progress > 0 ? progress : 100}%`"></div>
                    </div>
                    <p class="text-xs text-ink-400">Keep this page open for the fastest results — each progress check nudges the queue along. Leaving is safe, but scoring will slow to roughly 10-minute steps.</p>
                    <p x-show="coldStartLikely || elapsed >= 8" x-cloak class="text-xs text-info-700">
                        <span x-show="coldStartLikely">A service looks asleep — the first few results can take up to ~2 minutes while it wakes up.</span>
                        <span x-show="!coldStartLikely">If a service was asleep, the first few results can take up to ~2 minutes.</span>
                    </p>
                </div>
            </div>

            <button @click="showConfirm = true" :disabled="running"
                    class="btn btn-primary flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="!running">Run Full Batch ({{ $totalReady }})</span>
                <span x-show="running" x-cloak>Running…</span>
            </button>
        </div>

        {{-- Confirm modal --}}
        <div x-show="showConfirm" x-cloak
             role="dialog" aria-modal="true" aria-labelledby="batch-confirm-title"
             class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
             @keydown.escape.window="showConfirm = false">
            <div class="card shadow-2xl max-w-md w-full"
                 @click.outside="showConfirm = false">
                <div class="card-head">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-forest-50 dark:bg-forest-900/40 grid place-items-center flex-shrink-0">
                            <x-heroicon-o-arrow-path class="w-4 h-4 text-forest-700 dark:text-forest-400" />
                        </div>
                        <div class="card-title" id="batch-confirm-title">Run Batch Health Assessment?</div>
                    </div>
                    <button @click="showConfirm = false" class="btn btn-ghost p-1.5">
                        <x-heroicon-o-x-mark class="w-4 h-4" />
                    </button>
                </div>
                <div class="card-body space-y-4">
                    <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc]">
                        This will assess <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $totalReady }} senior(s)</strong> with processed QoL surveys and update their current risk scores and profile group assignments.
                        @if($totalEligible > $totalReady)
                        <span class="text-ink-400 dark:text-[#6b7570]">({{ $totalEligible - $totalReady }} others have unprocessed surveys and will be skipped.)</span>
                        @endif
                    </p>
                    <div class="rounded-xl bg-moderate-50 dark:bg-moderate-50/10 border border-moderate-100 dark:border-moderate-700/30 px-4 py-3 text-[12px] text-moderate-700 dark:text-[#d4a830] space-y-1.5">
                        <p class="font-semibold">Only run this when intentionally recomputing results.</p>
                        <p>For seeded seniors, results are normally sourced from the validated notebook export.</p>
                        <p>When that source is disabled, scores will be recomputed by the live model instead.</p>
                    </div>
                    <p class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">Jobs are queued and processed in the background. Keeping this page open makes them finish faster; leaving is safe but slower.</p>
                    <div class="flex gap-2 justify-end pt-1 border-t border-paper-rule dark:border-[#2b3530]">
                        <button @click="showConfirm = false" class="btn">Cancel</button>
                        <button @click="start()" class="btn btn-primary">
                            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                            Confirm &amp; Run Batch
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Queue table --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div>
                <div class="card-title">Eligible Seniors</div>
                <div class="card-sub">Seniors with at least one QoL survey · {{ $totalEligible }} total</div>
            </div>
            <form method="GET" action="{{ route('ml.batch') }}" class="flex items-center gap-2">
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search name, OSCA ID or System ID…" class="form-input text-[12px] max-w-[200px]">
                @if (request('search'))
                <a href="{{ route('ml.batch') }}" class="btn btn-ghost text-[12px]">Clear</a>
                @endif
            </form>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Senior</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Last Survey</th>
                    <th class="th text-center">Last Assessment</th>
                    <th class="th text-center">Current Risk</th>
                    <th class="th text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pending as $senior)
                @php $ml = $senior->latestMlResult; $survey = $senior->latestQolSurvey; @endphp
                <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors group">
                    <td class="td font-medium text-ink-900">
                        <div>{{ $senior->full_name }}</div>
                        <div class="text-[10.5px] font-normal font-mono mt-0.5">
                            <span class="{{ $senior->official_osca_id ? 'font-semibold text-ink-700 dark:text-[#d8d4c8]' : 'italic text-ink-300 dark:text-[#4a5550]' }}">OSCA: {{ $senior->official_osca_id_display }}</span>
                            <span class="text-ink-400 dark:text-[#6b7570]"> · SYS: {{ $senior->osca_id }}</span>
                        </div>
                    </td>
                    <td class="td text-ink-500">{{ $senior->barangay }}</td>
                    <td class="td text-center text-ink-400">
                        {{ $survey?->survey_date?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="td text-center text-ink-400">
                        {!! $ml?->processed_at?->diffForHumans() ?? '<span class="text-high-700 font-semibold text-xs">Never</span>' !!}
                    </td>
                    @php
                        // ml_queued_at persists across reload (see the migration's
                        // comment) — a row can be "Queued" even if the live
                        // progress panel above reset to idle because the page
                        // was reloaded mid-batch.
                        $rowQueued = $senior->ml_queued_at
                            && ! ($ml && $ml->processed_at && $ml->processed_at->gte($senior->ml_queued_at));
                    @endphp
                    <td class="td text-center" data-risk-badge>
                        @if ($rowQueued)
                        <span class="badge badge-info">Queued</span>
                        @elseif ($ml)
                        <span class="badge {{ match($ml->overall_risk_level) {
                            'HIGH'     => 'badge-high',
                            'MODERATE' => 'badge-moderate',
                            'LOW'      => 'badge-low',
                            default    => 'badge-neutral',
                        } }} {{ $ml->priority_flag === 'urgent' ? 'ring-1 ring-orange-400' : '' }}">
                            {{ $ml->overall_risk_level }}{{ $ml->priority_flag === 'urgent' ? ' ⚠' : '' }}</span>
                        @else
                        <span class="text-ink-300 text-xs">No data</span>
                        @endif
                    </td>
                    <td class="td text-center"
                        x-data="{
                            loading: false, done: false, err: '',
                            pollTimer: null, pollCount: 0, pollMax: 60,
                            baseTs: {{ $ml?->processed_at?->timestamp ?? 0 }},
                            resultUrl: '{{ route('ml.result.senior', $senior) }}',
                            async run() {
                                if (this.loading) return;
                                this.loading = true; this.err = ''; this.pollCount = 0;
                                // Fresh pre-flight check before this row's Run
                                // request goes out — see window.OSCA.requireMl()
                                // (app.js) and the shared modal in ml-service-guard.js.
                                const ready = await window.OSCA.requireMl();
                                if (!ready) { this.loading = false; return; }
                                fetch('{{ route('ml.run.single', $senior) }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                                })
                                .then(r => r.json())
                                .then(d => {
                                    if (d.error) { this.loading = false; this.err = d.error; return; }
                                    this.poll();
                                })
                                .catch(() => { this.loading = false; this.err = 'Request failed'; });
                            },
                            poll() {
                                this.pollTimer = setInterval(() => {
                                    this.pollCount++;
                                    if (this.pollCount >= this.pollMax) {
                                        // Our hosting tier's queue drains every ~10
                                        // min — longer than this poll window — so
                                        // this is very likely still queued, not
                                        // lost. Reload so the row's own Queued
                                        // badge (driven by ml_queued_at) takes over
                                        // instead of showing a false error here.
                                        clearInterval(this.pollTimer);
                                        Livewire.navigate(window.location.href);
                                        return;
                                    }
                                    fetch(this.resultUrl, { headers: { 'Accept': 'application/json' } })
                                    .then(r => r.json())
                                    .then(d => {
                                        if (d.ready && d.processed_at && d.processed_at > this.baseTs) {
                                            clearInterval(this.pollTimer);
                                            this.loading = false;
                                            this.done = true;
                                            this.$el.closest('tr').querySelector('[data-risk-badge]').innerHTML = d.risk_level;
                                        }
                                    })
                                    .catch(() => {});
                                }, 3000);
                            }
                        }">
                        <button @click="run()" :disabled="loading"
                                class="btn btn-ghost text-xs px-2 py-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                :class="done ? 'text-low-700' : ''">
                            <template x-if="loading">
                                <span class="flex items-center gap-1">
                                    <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    Running
                                </span>
                            </template>
                            <template x-if="!loading && done"><span>Done</span></template>
                            <template x-if="!loading && !done"><span>Re-run</span></template>
                        </button>
                        <p x-show="err" x-text="err" x-cloak class="text-xs text-critical-700 mt-1"></p>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="td text-center py-16">
                        <p class="font-serif text-lg text-ink-700">No eligible seniors found.</p>
                        <p class="text-sm text-ink-400 mt-1">Add a QoL survey to a senior citizen to include them in batch analysis.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if ($pending->hasPages())
        <div class="border-t border-paper-rule px-5 py-3">{{ $pending->links() }}</div>
        @endif
    </div>

</div>
@endsection
