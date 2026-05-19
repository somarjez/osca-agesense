@extends('layouts.app')
@section('page-title', 'Analysis Service Status')
@section('page-subtitle', 'Health assessment service status and processing statistics')

@section('content')
<div class="space-y-5">

    {{-- Mode banner --}}
    @php $mode = $health['mode'] ?? 'php_fallback'; @endphp

    <div class="card">
        <div class="card-body flex items-center gap-4">
            <div class="w-3 h-3 rounded-full flex-shrink-0
                @if ($mode === 'http') bg-low-500
                @elseif ($mode === 'local_python') bg-high-500
                @else bg-critical-500
                @endif"></div>
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
            @if ($mode !== 'http')
            <form method="POST" action="{{ route('ml.start') }}">
                @csrf
                <button type="submit" class="btn btn-primary flex-shrink-0">Start Services</button>
            </form>
            @endif
        </div>
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
                    {{ $ok ? 'bg-low-50' : 'bg-critical-50' }}">
                    <div class="w-2.5 h-2.5 rounded-full {{ $ok ? 'bg-low-500' : 'bg-critical-500' }}"></div>
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

    {{-- Instructions --}}
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
