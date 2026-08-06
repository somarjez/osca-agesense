@extends('layouts.app')
@section('page-title', 'Analysis Service Status')
@section('page-subtitle', 'Health assessment service status and processing statistics')

@section('content')
<div class="space-y-5">

    {{-- Mode banner --}}
    @php $mode = $health['mode'] ?? 'php_fallback'; @endphp

    <div x-data="{
            stopOpen: false, restartOpen: false,
            waking: false, wakeDone: false, wakeGaveUp: false, wakeError: null,
            wakeElapsed: 0, wakeFailCount: 0, wakeRequestInFlight: false,
            wakeTicker: null, wakePoller: null, wakeStartedAt: null,
            wakeStatusUrl: '{{ route('ml.wake-status') }}',
            wakeUrl: '{{ route('ml.wake') }}',
            preprocessUrl: '{{ $preprocessUrl }}',
            inferenceUrl: '{{ $inferenceUrl }}',
            csrfToken: '{{ csrf_token() }}',
            // Cold boots on Render's free tier are documented to range
            // ~122s-180s+ per service, and the Laravel app itself can
            // separately need its own ~40-90s cold start before this page
            // (and this countdown) even loads — widened further so the
            // ceiling has real headroom over the slow end.
            wakeMaxSeconds: 420,
            initWake() {
                // Backgrounded/locked tabs throttle or fully suspend
                // setInterval, so a status transition to online that
                // happens while hidden can otherwise sit unnoticed until
                // the next (possibly very late) timer tick. Re-check the
                // moment the tab is foregrounded again instead of waiting.
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden && this.waking) this.checkStatus();
                });
                // wire:navigate keeps this running on a detached component
                // after the user leaves the page — without this, navigating
                // away mid-wake (up to wakeMaxSeconds later) fired
                // finishWake()'s unconditional location.reload() on whatever
                // page the user had since moved to.
                document.addEventListener('livewire:navigating', () => this.stopWake());
            },
            startWake() {
                if (this.waking) return;
                this.waking = true; this.wakeDone = false; this.wakeGaveUp = false; this.wakeError = null;
                this.wakeStartedAt = Date.now(); this.wakeElapsed = 0; this.wakeFailCount = 0;
                // Bonus fast path only, not depended on — a real browser
                // visiting these exact URLs reliably wakes a fully-cold
                // Render free-tier container, but only on a device whose
                // network/browser can actually reach *.onrender.com
                // directly. That's not guaranteed (a device-side firewall,
                // DNS filter, or ad-blocker can silently drop it — found in
                // production to make the button appear to work on some
                // devices and silently time out on others, with Render
                // never receiving a single request from the failing
                // device). wakeLoop() below is the mechanism this doesn't
                // depend on: it runs server-side, from this app's own
                // network egress.
                fetch(this.preprocessUrl + '/health', { mode: 'no-cors' }).catch(() => {});
                fetch(this.inferenceUrl + '/health', { mode: 'no-cors' }).catch(() => {});
                this.wakeTicker = setInterval(() => {
                    // Date.now()-based, not a naive counter — a throttled or
                    // delayed tick still shows the true elapsed wall-clock
                    // time once it fires, instead of drifting low.
                    this.wakeElapsed = Math.floor((Date.now() - this.wakeStartedAt) / 1000);
                }, 1000);
                this.wakeLoop();
                this.pollWake();
            },
            pollWake() {
                this.wakePoller = setInterval(() => {
                    if (this.wakeElapsed >= this.wakeMaxSeconds) {
                        this.stopWake();
                        this.wakeGaveUp = true;
                        return;
                    }
                    this.checkStatus();
                    this.wakeLoop();
                }, 3000);
            },
            // Repeatedly POSTs /ml/wake — each call is a single bounded
            // (~15s) server-side wake attempt (MlService::wakeAttempt()).
            // wakeRequestInFlight guards against overlapping calls; since
            // this also gets called every 3s tick from pollWake(), a call
            // that's still in flight is simply skipped rather than piling
            // up, so the real cadence is paced by however long each
            // attempt actually took (at least 3s apart either way).
            wakeLoop() {
                if (this.wakeRequestInFlight || !this.waking) return;
                this.wakeRequestInFlight = true;
                fetch(this.wakeUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' }
                })
                .then(r => {
                    if (!r.ok) { const e = new Error('wake request failed: ' + r.status); e.httpError = true; throw e; }
                    return r.json();
                })
                .then(d => {
                    this.wakeRequestInFlight = false;
                    if (d.ready && this.waking) this.finishWake();
                })
                .catch(err => {
                    this.wakeRequestInFlight = false;
                    if (err && err.httpError) {
                        // The app itself rejected the request (expired
                        // session, rate limited, server error) — distinct
                        // from still booting, surface it instead of
                        // silently retrying forever.
                        this.stopWake();
                        this.wakeError = 'Lost connection to the server while waking services — please reload the page.';
                    }
                    // A thrown network error (not httpError) is a transient
                    // blip reaching our own server — tolerated, the next 3s
                    // tick retries.
                });
            },
            checkStatus() {
                fetch(this.wakeStatusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(r => {
                        if (!r.ok) throw new Error('status check failed: ' + r.status);
                        return r.json();
                    })
                    .then(d => {
                        this.wakeFailCount = 0;
                        if (d.mode === 'http') this.finishWake();
                    })
                    .catch(() => {
                        this.wakeFailCount++;
                        // Tolerate a couple of transient blips (a single
                        // failed poll during a multi-minute wait shouldn't
                        // abort the whole flow) but a *sustained* run of
                        // failures — expired session, lost connection, rate
                        // limited — is a reliable signal worth surfacing
                        // instead of silently counting with nothing actually
                        // checking anymore.
                        if (this.wakeFailCount >= 3) {
                            this.stopWake();
                            this.wakeError = 'Lost connection to the server while waking services — please reload the page.';
                        }
                    });
            },
            finishWake() {
                this.stopWake();
                this.wakeDone = true;
                // Soft SPA refresh instead of a hard reload — re-fetches this same
                // page's fresh status without throwing away the persisted
                // sidebar/topbar or forcing a full asset reload.
                setTimeout(() => Livewire.navigate(window.location.href), 1200);
            },
            stopWake() {
                this.waking = false;
                clearInterval(this.wakeTicker);
                clearInterval(this.wakePoller);
            },
            fmt(s) {
                const m = Math.floor(s / 60), sec = s % 60;
                return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
            }
         }"
         x-init="initWake(); if (@js($mode !== 'http')) startWake()"
    >
        <div class="card">
            <div class="card-body flex items-center gap-4">
                <span class="flex-shrink-0 status-dot
                    @if ($mode === 'http') status-dot-ok
                    @elseif ($mode === 'local_python') status-dot-warn
                    @else status-dot-err
                    @endif"></span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-0.5">
                        <p class="font-semibold text-ink-900">
                            @if ($mode === 'http') HTTP Services
                            @elseif ($mode === 'local_python') Local Python Runner
                            @else PHP Heuristic Fallback
                            @endif
                        </p>
                        <span class="badge
                            @if ($mode === 'http') badge-low
                            @elseif ($mode === 'local_python') badge-high
                            @else badge-critical
                            @endif">
                            @if ($mode === 'http') Online
                            @elseif ($mode === 'local_python') Degraded
                            @else Offline
                            @endif
                        </span>
                    </div>
                    <p class="text-sm text-ink-500">
                        @if ($mode === 'http')
                            All analysis services are online and running normally.
                        @elseif ($mode === 'local_python')
                            Main services are unavailable, but a backup local process is active. Assessments will still run.
                        @else
                            Analysis services are offline. Results are estimated using built-in rules.
                        @endif
                    </p>
                </div>
                @if (! $canControlLocalServices)
                {{-- These controls shell out to python/start_services.ps1 / stop_services.ps1
                     (PowerShell) — fundamentally Windows-only, see
                     MlService::localServiceControlAvailable(). On the hosted
                     deployment osca-preprocess/osca-inference are independent
                     Render services with no local process to start/stop — instead,
                     "Wake up ML services" pings each one's /health endpoint, which
                     is all it takes to trigger Render waking a sleeping free-tier
                     container (see MlService::wakeAttempt()). Auto-fires on load
                     when this page's initial $mode wasn't 'http'. --}}
                <div class="text-right flex-shrink-0 max-w-xs">
                    <template x-if="!waking && !wakeDone && !wakeGaveUp && !wakeError">
                        <div>
                            <button type="button" @click="startWake()" class="btn btn-secondary">Wake up ML services</button>
                            <p class="text-xs text-ink-400 mt-2">
                                Independent Render services — sleep/wake happens automatically, or
                                trigger it proactively here instead of eating the wait on your next request.
                            </p>
                        </div>
                    </template>
                    <template x-if="waking">
                        <div class="flex items-center justify-end gap-2 text-sm text-info-700">
                            <svg class="w-4 h-4 animate-spin flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            <span>Waking up services&hellip; <span x-text="fmt(wakeElapsed)" class="font-mono"></span> <span class="text-ink-400">(usually 1&ndash;3 min)</span></span>
                        </div>
                    </template>
                    <template x-if="wakeDone">
                        <div class="flex items-center justify-end gap-1.5 text-sm text-low-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <span>Services are online &mdash; refreshing&hellip;</span>
                        </div>
                    </template>
                    <template x-if="wakeGaveUp">
                        <div class="text-sm text-high-700">
                            <p>Still waking up &mdash; taking longer than usual.</p>
                            <button type="button" @click="startWake()" class="btn btn-ghost text-xs mt-1">Try again</button>
                        </div>
                    </template>
                    <template x-if="wakeError">
                        <div class="flex items-start justify-end gap-1.5 text-sm text-critical-700 dark:text-[#e08070]">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 mt-0.5 flex-shrink-0" />
                            <div>
                                <p x-text="wakeError"></p>
                                <button type="button" @click="startWake()" class="btn btn-ghost text-xs mt-1">Try again</button>
                            </div>
                        </div>
                    </template>
                </div>
                @elseif ($mode !== 'http')
                <form method="POST" action="{{ route('ml.start') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary flex-shrink-0">Start Services</button>
                </form>
                @else
                <form method="POST" action="{{ route('ml.stop') }}" x-ref="stopForm" class="hidden">
                    @csrf
                </form>
                <form method="POST" action="{{ route('ml.restart') }}" x-ref="restartForm" class="hidden">
                    @csrf
                </form>
                <button type="button" @click="restartOpen = true" class="btn btn-secondary flex-shrink-0">Restart Services</button>
                <button type="button" @click="stopOpen = true" class="btn btn-danger flex-shrink-0">Stop Services</button>
                @endif
            </div>
        </div>

        <x-confirm-modal show="stopOpen"
                         title="Stop ML services?"
                         confirm="$refs.stopForm.requestSubmit()"
                         confirm-label="Stop Services">
            <p>This will shut down the data preparation and risk assessment services. Any analysis in progress for other users will be interrupted, and the app will fall back to the backup local process or built-in rules until services are started again.</p>
        </x-confirm-modal>

        <x-confirm-modal show="restartOpen"
                         title="Restart ML services?"
                         tone="primary"
                         confirm="$refs.restartForm.requestSubmit()"
                         confirm-label="Restart Services">
            <p>This will briefly take the data preparation and risk assessment services offline while they restart. Any analysis in progress for other users will be interrupted.</p>
        </x-confirm-modal>
    </div>

    {{-- Service Health --}}
    @php
    $healthDisplay = [
        'preprocessor' => ['label' => 'Data Preparation', 'port' => '5001', 'desc' => 'Prepares survey data for analysis'],
        'inference'     => ['label' => 'Risk Assessment',  'port' => '5002', 'desc' => 'Indicates possible risk levels for decision support based on multidimensional senior citizen indicators'],
        'local_runner'  => ['label' => 'Backup Process',   'port' => null,   'desc' => 'Runs locally when main services are down'],
    ];
    @endphp
    <div class="grid grid-cols-3 gap-4">
        @foreach ($healthDisplay as $key => $meta)
        @php $status = $health[$key] ?? 'unknown'; $ok = in_array($status, ['ok', 'available']); @endphp
        <div class="card">
            <div class="card-body flex items-center gap-4">
                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0
                    {{ $ok ? 'bg-low-50' : 'bg-high-50' }}">
                    <span class="status-dot {{ $ok ? 'status-dot-ok' : 'status-dot-err' }}"></span>
                </div>
                <div class="min-w-0">
                    <p class="font-semibold text-sm text-ink-800">{{ $meta['label'] }}</p>
                    @if ($meta['port'])
                        <p class="text-xs text-ink-400">Port {{ $meta['port'] }} · {{ $meta['desc'] }}</p>
                    @else
                        <p class="text-xs text-ink-400">{{ $meta['desc'] }}</p>
                    @endif
                    <p class="text-xs font-semibold {{ $ok ? 'text-low-700' : 'text-critical-700' }} mt-0.5 capitalize">{{ $status }}</p>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Pipeline Stats --}}
    <div class="grid grid-cols-4 gap-4">
        <x-kpi label="Total Processed" :value="number_format($stats['total_processed'])" accent="forest" />
        <x-kpi label="Urgent Priority" :value="number_format($stats['urgent_count'])"    accent="high"     valueColor="text-high-700" />
        <x-kpi label="Unprocessed"     :value="number_format($stats['unprocessed'])"     accent="high"     valueColor="text-high-700" />
        <x-kpi label="Last Run"        :value="$stats['last_run'] ? \Carbon\Carbon::parse($stats['last_run'])->diffForHumans() : 'Never'" accent="forest" />
    </div>

    {{-- Prediction Source Summary --}}
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">ML Prediction Source Summary</div>
                <div class="card-sub">Breakdown of how current risk indicators were computed</div>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                <div class="rounded-xl border border-forest-200 bg-forest-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-forest-600 mb-1">Notebook-Validated Cache</div>
                    <div class="text-2xl font-bold font-mono text-forest-800">{{ number_format($stats['notebook_cache']) }}</div>
                    <div class="text-[11px] text-forest-600 mt-0.5">Original 283 seed seniors</div>
                </div>
                <div class="rounded-xl border border-info-200 bg-info-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-info-600 mb-1">Live ML Model</div>
                    <div class="text-2xl font-bold font-mono text-info-800">{{ number_format($stats['live_model']) }}</div>
                    <div class="text-[11px] text-info-600 mt-0.5">New seniors · GBR/RFR pipeline</div>
                </div>
                <div class="rounded-xl border border-paper-rule bg-paper-2 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-500 mb-1">Heuristic Fallback</div>
                    <div class="text-2xl font-bold font-mono text-ink-700">{{ number_format($stats['fallback']) }}</div>
                    <div class="text-[11px] text-ink-400 mt-0.5">Python unavailable at run time</div>
                </div>
                <div class="rounded-xl border border-high-200 bg-high-50 px-4 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-high-600 mb-1">Critical Flag</div>
                    <div class="text-2xl font-bold font-mono text-high-800">{{ number_format($stats['critical_count']) }}</div>
                    <div class="text-[11px] text-high-600 mt-0.5">HIGH + composite ≥ 0.70</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-[12px] text-ink-500 border-t border-paper-rule pt-4">
                <div>
                    <span class="font-semibold text-ink-700">Model Version:</span>
                    <span class="font-mono ml-1">{{ $stats['model_version'] }}</span>
                </div>
                <div>
                    <span class="font-semibold text-ink-700">Active ML Mode:</span>
                    <span class="ml-1 font-semibold
                        {{ str_contains($stats['active_ml_mode'], 'Notebook') ? 'text-forest-700' : 'text-info-700' }}">
                        {{ $stats['active_ml_mode'] }}
                    </span>
                </div>
                <div>
                    <span class="font-semibold text-ink-700">UMAP Mode:</span>
                    <span class="ml-1 text-forest-700 font-semibold">{{ $stats['umap_mode'] }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Predictions info strip --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-ink-400 -mt-2">
        <span class="font-semibold text-ink-500">Predictions:</span>
        @if ($stats['notebook_cache'] > 0)
            <span class="inline-flex items-center gap-1 font-semibold px-1.5 py-0.5 rounded text-forest-700 bg-forest-50 border border-forest-200">
                Notebook-Validated Cache: {{ number_format($stats['notebook_cache']) }}
            </span>
        @endif
        @if ($stats['live_model'] > 0)
            <span class="inline-flex items-center gap-1 font-semibold px-1.5 py-0.5 rounded text-info-700 bg-info-50 border border-info-200">
                Live ML Model: {{ number_format($stats['live_model']) }}
            </span>
        @endif
        @if ($stats['fallback'] > 0)
            <span class="inline-flex items-center gap-1 font-semibold px-1.5 py-0.5 rounded text-ink-600 bg-paper-2 border border-paper-rule">
                Fallback: {{ number_format($stats['fallback']) }}
            </span>
        @endif
        <span class="text-ink-300">&middot;</span>
        <span>Model: <span class="font-mono font-semibold text-ink-600">{{ $stats['model_version'] }}</span></span>
        @if (app()->environment('local'))
        <span class="text-ink-300">&middot;</span>
        <span>DB: <span class="font-mono font-semibold text-ink-600">{{ config('database.connections.mysql.host') }}:{{ config('database.connections.mysql.database') }}</span></span>
        @endif
    </div>

    {{-- Model & Pipeline Status Panel — DB host/name only shown locally, never in production --}}
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">Model &amp; Pipeline Status</div>
                @if (app()->environment('local'))
                <div class="card-sub">Current runtime configuration — do not share DB password publicly</div>
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-[12.5px]">
                @if (app()->environment('local'))
                <div>
                    <div class="eyebrow mb-1">DB Host</div>
                    <div class="font-mono font-semibold text-ink-800">{{ config('database.connections.mysql.host') }}:{{ config('database.connections.mysql.port', 3306) }}</div>
                </div>
                <div>
                    <div class="eyebrow mb-1">DB Database</div>
                    <div class="font-mono font-semibold text-ink-800">{{ config('database.connections.mysql.database') }}</div>
                </div>
                @endif
                <div>
                    <div class="eyebrow mb-1">Model Version</div>
                    <div class="font-mono font-semibold text-ink-800">{{ $stats['model_version'] }}</div>
                </div>
                <div>
                    <div class="eyebrow mb-1">UMAP Mode</div>
                    <div class="font-semibold text-forest-700">{{ $stats['umap_mode'] }}</div>
                </div>
                <div>
                    <div class="eyebrow mb-1">Notebook Overrides</div>
                    <div class="font-semibold {{ str_contains($stats['active_ml_mode'], 'Notebook') ? 'text-forest-700' : 'text-moderate-700' }}">
                        {{ $stats['active_ml_mode'] }}
                    </div>
                </div>
                <div>
                    <div class="eyebrow mb-1">Notebook Cache</div>
                    <div class="font-mono font-semibold text-forest-700">{{ number_format($stats['notebook_cache']) }}</div>
                </div>
                <div>
                    <div class="eyebrow mb-1">Live ML Model</div>
                    <div class="font-mono font-semibold text-info-700">{{ number_format($stats['live_model']) }}</div>
                </div>
                <div>
                    <div class="eyebrow mb-1">Fallback Results</div>
                    <div class="font-mono font-semibold {{ $stats['fallback'] > 0 ? 'text-high-700' : 'text-ink-400' }}">{{ number_format($stats['fallback']) }}</div>
                </div>
            </div>
            @if (app()->environment('local'))
            <p class="text-[11px] text-ink-400 mt-4 border-t border-paper-rule pt-3">
                For final defense, all devices should point to the same DB host. See
                <span class="font-mono">docs/DATABASE_SHARING.md</span> for export/import and shared MySQL setup steps.
            </p>
            @endif
        </div>
    </div>

    {{-- Instructions — local dev only; Render's Python services aren't started via PowerShell --}}
    @if (app()->environment('local'))
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">Starting the Python Services</div>
                <div class="card-sub">Run these commands in a terminal from the project root</div>
            </div>
        </div>
        <div class="card-body space-y-3">
            <div class="bg-slate-900 rounded-lg p-4 font-mono text-xs text-emerald-400 space-y-1">
                <p><span class="text-slate-400"># PowerShell — start both services (Windows)</span></p>
                <p>cd python; .\start_services.ps1</p>
                <p class="mt-2"><span class="text-slate-400"># Or start individually</span></p>
                <p>cd python/services && python preprocess_service.py</p>
                <p>cd python/services && python inference_service.py</p>
            </div>
            <p class="text-xs text-ink-400">
                Services run on <code class="bg-paper-2 px-1 rounded">localhost:5001</code> (preprocessor) and
                <code class="bg-paper-2 px-1 rounded">localhost:5002</code> (inference).
                Configure <code class="bg-paper-2 px-1 rounded">PYTHON_SERVICE_URL</code> in .env to change the base URL.
            </p>
        </div>
    </div>
    @endif

    {{-- Batch prompt --}}
    @if ($stats['unprocessed'] > 0)
    <div class="card">
        <div class="card-body flex items-center gap-4">
            <div class="flex-1">
                <p class="font-semibold text-ink-900">{{ number_format($stats['unprocessed']) }} senior(s) have not been assessed yet</p>
                <p class="text-sm text-ink-500 mt-0.5">Run a batch assessment for all pending seniors.</p>
            </div>
            <a href="{{ route('ml.batch') }}" class="btn btn-primary flex-shrink-0">Run Batch Analysis →</a>
        </div>
    </div>
    @endif

</div>
@endsection
