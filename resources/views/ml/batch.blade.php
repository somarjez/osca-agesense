@extends('layouts.app')
@section('page-title', 'Batch Health Assessment')
@section('page-subtitle', 'Run health grouping and risk scoring for all eligible seniors')

@section('content')
<div class="space-y-5">

    {{-- Run panel --}}
    <div x-data="{
            running: false,
            done: false,
            showConfirm: false,
            errMsg: '',
            resultMsg: '',
            elapsed: 0,
            timer: null,
            pollTimer: null,
            processed: 0,
            failed: 0,
            total: {{ $totalEligible }},
            progress: 0,
            csrfToken: '{{ csrf_token() }}',
            batchUrl: '{{ route('ml.batch.run') }}',
            statusUrl: '{{ route('ml.batch.status') }}',
            cacheKey: '',
            batchId: '',
            start() {
                this.showConfirm = false;
                this.running = true; this.done = false; this.errMsg = ''; this.resultMsg = '';
                this.processed = 0; this.failed = 0; this.progress = 0;
                this.elapsed = 0;
                this.timer = setInterval(() => this.elapsed++, 1000);
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
                        if (d.finished || d.cancelled) {
                            clearInterval(this.pollTimer);
                            clearInterval(this.timer);
                            this.running = false;
                            this.done    = true;
                            this.resultMsg = `Batch complete. Processed: ${d.processed}. Failed: ${d.failed}.`;
                            if (d.failed > 0) this.errMsg = `${d.failed} senior(s) failed. Check the queue failed_jobs table.`;
                            setTimeout(() => location.reload(), 2000);
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
                <p class="text-sm text-ink-500 mb-3">Assesses all seniors with a QoL survey: prepares data → assigns health group → scores risk → generates recommendations.</p>

                {{-- Result / error banners --}}
                <p x-show="resultMsg && !running" x-text="resultMsg"
                   :class="errMsg ? 'text-critical-700 bg-critical-50 border border-critical-100' : 'text-low-700 bg-low-50 border border-low-100'"
                   class="text-sm px-3 py-2 rounded-lg mb-2" x-cloak></p>
                <p x-show="errMsg && !running" x-text="errMsg"
                   class="text-xs text-critical-700" x-cloak></p>

                {{-- Progress bar while running --}}
                <div x-show="running" class="space-y-1.5" x-cloak>
                    <div class="flex items-center justify-between text-sm text-forest-700 font-medium">
                        <span x-text="`Processing ${total} seniors… ${processed} done`"></span>
                        <span x-text="fmt(elapsed)" class="text-ink-400 font-normal text-xs"></span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill bg-forest-600 transition-all duration-500"
                             :style="`width:${progress > 0 ? progress : 100}%`"
                             :class="progress === 0 ? 'animate-pulse' : ''"></div>
                    </div>
                    <p class="text-xs text-ink-400">Queued — worker is processing in the background. You can safely close this tab.</p>
                </div>
            </div>

            <button @click="showConfirm = true" :disabled="running"
                    class="btn btn-primary flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="!running">Run Full Batch ({{ $totalEligible }})</span>
                <span x-show="running" x-cloak>Running…</span>
            </button>
        </div>

        {{-- Confirm modal --}}
        <div x-show="showConfirm" x-cloak
             class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
             @keydown.escape.window="showConfirm = false">
            <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6"
                 @click.outside="showConfirm = false">
                <div class="flex items-start gap-4 mb-4">
                    <div class="w-10 h-10 rounded-full bg-forest-50 flex items-center justify-center flex-shrink-0">
                        <svg class="h-5 w-5 text-forest-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-slate-800 text-base">Run Batch Health Assessment?</h3>
                        <p class="text-sm text-slate-500 mt-1">
                            This will assess <strong class="text-slate-700">{{ $totalEligible }} senior(s)</strong>: prepare data → assign health group → score risk → generate recommendations.
                        </p>
                        <p class="text-xs text-slate-400 mt-2">Jobs are queued and processed in the background. Progress updates every 3 seconds. You can safely close this tab — the queue worker continues independently.</p>
                    </div>
                </div>
                <div class="flex gap-3 justify-end pt-2 border-t border-slate-100">
                    <button @click="showConfirm = false" class="btn">Cancel</button>
                    <button @click="start()" class="btn btn-primary">Start Batch</button>
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
                <tr class="hover:bg-paper-2 transition-colors group">
                    <td class="td font-medium text-ink-900">{{ $senior->full_name }}</td>
                    <td class="td text-ink-500">{{ $senior->barangay }}</td>
                    <td class="td text-center text-ink-400">
                        {{ $survey?->survey_date?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="td text-center text-ink-400">
                        {!! $ml?->processed_at?->diffForHumans() ?? '<span class="text-high-700 font-semibold text-xs">Never</span>' !!}
                    </td>
                    <td class="td text-center" data-risk-badge>
                        @if ($ml)
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
                            run() {
                                this.loading = true; this.err = ''; this.pollCount = 0;
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
                                        clearInterval(this.pollTimer);
                                        this.loading = false;
                                        this.err = 'Timed out. Refresh to see result.';
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
