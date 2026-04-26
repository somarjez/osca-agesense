@extends('layouts.app')
@section('page-title', 'ML Service Status')
@section('page-subtitle', 'Python microservice health and pipeline statistics')

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
                        Both Python microservices are reachable. Full ML pipeline is active.
                    @elseif ($mode === 'local_python')
                        HTTP services are down but a local Python environment is available. Analysis runs via subprocess.
                    @else
                        Python services unavailable. Results are computed using PHP rule-based heuristics.
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
        'preprocessor' => ['label' => 'Preprocessor', 'port' => '5001', 'desc' => 'Feature pipeline'],
        'inference'     => ['label' => 'Inference',    'port' => '5002', 'desc' => 'Cluster & risk model'],
        'local_runner'  => ['label' => 'Local Python', 'port' => null,   'desc' => 'Subprocess fallback'],
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
        <x-kpi label="Critical Risk"   :value="number_format($stats['critical_count'])"  accent="critical" valueColor="text-critical-700" />
        <x-kpi label="Unprocessed"     :value="number_format($stats['unprocessed'])"     accent="high"     valueColor="text-high-700" />
        <x-kpi label="Last Run"        :value="$stats['last_run'] ? \Carbon\Carbon::parse($stats['last_run'])->diffForHumans() : 'Never'" accent="forest" />
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
                <p class="font-semibold text-ink-900">{{ number_format($stats['unprocessed']) }} senior(s) have no ML analysis yet</p>
                <p class="text-sm text-ink-500 mt-0.5">Run the full pipeline for all unprocessed seniors in Batch Analysis.</p>
            </div>
            <a href="{{ route('ml.batch') }}" class="btn btn-primary flex-shrink-0">Run Batch Analysis →</a>
        </div>
    </div>
    @endif

</div>
@endsection
