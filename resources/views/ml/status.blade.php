@extends('layouts.app')
@section('page-title', 'ML Service Status')
@section('page-subtitle', 'Python microservice health and pipeline statistics')

@section('content')
<div class="space-y-5">

    {{-- Health Cards --}}
    <div class="grid grid-cols-2 gap-4">
        @foreach ($health as $service => $status)
        <div class="bg-white border {{ $status==='ok' ? 'border-emerald-200' : 'border-red-200' }} rounded-xl p-5 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-full flex items-center justify-center text-2xl
                {{ $status==='ok' ? 'bg-emerald-100' : 'bg-red-100' }}">
                {{ $status==='ok' ? '✅' : '❌' }}
            </div>
            <div>
                <p class="font-semibold text-slate-800 capitalize">{{ $service }} Service</p>
                <p class="text-sm {{ $status==='ok' ? 'text-emerald-600' : 'text-red-600' }} font-medium">
                    {{ strtoupper($status) }}
                </p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Pipeline Stats --}}
    <div class="grid grid-cols-4 gap-4">
        @foreach ([
            ['Total Processed', $stats['total_processed'], '🤖'],
            ['Critical Count',  $stats['critical_count'],  '🚨'],
            ['Unprocessed',     $stats['unprocessed'],     '⏳'],
            ['Last Run',        $stats['last_run'] ? \Carbon\Carbon::parse($stats['last_run'])->diffForHumans() : 'Never', '🕐'],
        ] as [$label, $val, $icon])
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
            <div class="text-2xl mb-2">{{ $icon }}</div>
            <p class="text-2xl font-bold text-slate-800">{{ $val }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $label }}</p>
        </div>
        @endforeach
    </div>

    {{-- Instructions --}}
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <h3 class="font-semibold text-slate-700 mb-3">Starting the Python Services</h3>
        <div class="space-y-3 text-sm text-slate-600">
            <div class="bg-slate-900 rounded-lg p-4 font-mono text-xs text-emerald-400 space-y-1">
                <p><span class="text-slate-400"># Install dependencies</span></p>
                <p>pip install flask scikit-learn numpy pandas umap-learn</p>
                <p class="mt-2"><span class="text-slate-400"># Start Preprocessing Service (port 5001)</span></p>
                <p>cd python/services && python preprocess_service.py</p>
                <p class="mt-2"><span class="text-slate-400"># Start Inference Service (port 5002)</span></p>
                <p>cd python/services && python inference_service.py</p>
                <p class="mt-2"><span class="text-slate-400"># Or use the combined start script</span></p>
                <p>bash python/start_services.sh</p>
            </div>
            <p class="text-xs text-slate-400">
                Services run on <code class="bg-slate-100 px-1 rounded">localhost:5001</code> (preprocessor) and
                <code class="bg-slate-100 px-1 rounded">localhost:5002</code> (inference).
                Configure <code class="bg-slate-100 px-1 rounded">PYTHON_SERVICE_URL</code> in .env to change the base URL.
            </p>
        </div>
    </div>

    {{-- Batch action --}}
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 flex items-center gap-4">
        <span class="text-2xl">⚡</span>
        <div class="flex-1">
            <p class="font-semibold text-amber-900">{{ $stats['unprocessed'] }} senior(s) have no ML analysis yet.</p>
            <p class="text-sm text-amber-700">Go to Batch Analysis to run the pipeline for all unprocessed seniors.</p>
        </div>
        <a href="{{ route('ml.batch') }}"
           class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 shadow-sm">
            Run Batch →
        </a>
    </div>
</div>
@endsection
