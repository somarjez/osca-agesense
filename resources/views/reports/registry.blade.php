@extends('layouts.app')
@section('page-title', 'Registry and Backup')
@section('page-subtitle', 'Preview and download the full senior citizen registry, and back up the database')

@section('content')
<div class="space-y-5">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="eyebrow text-forest-600 dark:text-forest-400 mb-1">Records</div>
            <h2 class="font-display text-2xl leading-tight text-ink-900 dark:text-[#e4e1d8]">Senior Registry</h2>
            <p class="text-sm text-ink-500 dark:text-[#8a9087] mt-0.5">A complete export of all active senior citizen records with their latest assessment.</p>
        </div>
        <a href="{{ route('reports.registry.export') }}" class="btn btn-primary">
            <x-heroicon-o-arrow-down-tray class="w-4 h-4" /> Download Full Registry (XLSX)
        </a>
    </div>

    {{-- Summary previews --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <div class="kpi"><div class="kpi-rule bg-forest-500"></div><div class="kpi-label">Active Seniors</div><div class="kpi-value">{{ number_format($stats['total']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-forest-400"></div><div class="kpi-label">Assessed</div><div class="kpi-value">{{ number_format($stats['assessed']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-ink-300"></div><div class="kpi-label">Not Assessed</div><div class="kpi-value">{{ number_format($stats['not_assessed']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-high-500"></div><div class="kpi-label">High Risk</div><div class="kpi-value text-high-700">{{ number_format($stats['high']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-moderate-500"></div><div class="kpi-label">Moderate</div><div class="kpi-value text-moderate-700">{{ number_format($stats['moderate']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-low-500"></div><div class="kpi-label">Barangays</div><div class="kpi-value">{{ number_format($stats['barangays']) }}</div></div>
    </div>

    {{-- Preview table --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Preview</div>
            <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">First {{ $preview->count() }} of {{ number_format($stats['total']) }} records · download for the full set</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">OSCA ID</th>
                        <th class="th">Name</th>
                        <th class="th">Barangay</th>
                        <th class="th text-center">Risk Level</th>
                        <th class="th">Profile Group</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($preview as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td font-mono text-[12px]">
                            <div class="{{ $senior->official_osca_id ? 'font-semibold text-ink-800 dark:text-[#e4e1d8]' : 'italic text-ink-300 dark:text-[#4a5550]' }}">
                                {{ $senior->official_osca_id_display }}
                            </div>
                            <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] mt-0.5">
                                SYS: {{ $senior->osca_id }}
                            </div>
                        </td>
                        <td class="td font-medium text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</td>
                        <td class="td text-ink-500 dark:text-[#8a9087]">{{ $senior->barangay }}</td>
                        <td class="td text-center">
                            @if ($ml)
                            <span class="badge {{ match($ml->overall_risk_level) {
                                'HIGH' => 'badge-high', 'MODERATE' => 'badge-moderate', 'LOW' => 'badge-low', default => 'badge-neutral',
                            } }}">{{ $ml->overall_risk_level }}</span>
                            @else
                            <span class="text-ink-300 dark:text-[#4a5550] text-xs">—</span>
                            @endif
                        </td>
                        <td class="td text-[12px] text-ink-500 dark:text-[#8a9087]">{{ $ml?->cluster_name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="td text-center py-12 text-ink-400">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Database backups --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Database Backups</div>
            <div x-data="{ open: false }">
                <button type="button" @click="open = true" class="btn btn-primary text-xs">
                    <x-heroicon-o-circle-stack class="w-3.5 h-3.5" /> Create Backup Now
                </button>
                <form x-ref="backupForm" method="POST" action="{{ route('reports.registry.backup') }}" class="hidden">
                    @csrf
                </form>
                <x-confirm-modal show="open"
                                 title="Create a database backup?"
                                 tone="primary"
                                 confirm="$refs.backupForm.submit()"
                                 confirm-label="Create backup">
                    <p>This creates a full backup of the database. Only the latest {{ \App\Services\DatabaseBackupService::DEFAULT_KEEP }} app-created backups are kept — older ones are deleted automatically.</p>
                </x-confirm-modal>
            </div>
        </div>
        <div class="px-5 pt-3">
            <x-alert type="warning">
                Backups contain senior-citizen personal and health data. Downloaded files must be stored securely and never shared over insecure channels. See <code>docs/BACKUP_AND_RECOVERY.md</code>.
            </x-alert>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">File</th>
                        <th class="th">Created</th>
                        <th class="th">Size</th>
                        <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $backup)
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td font-mono text-[12px] text-ink-800 dark:text-[#e4e1d8]">{{ $backup['name'] }}</td>
                        <td class="td text-ink-500 dark:text-[#8a9087]">{{ $backup['created_at']->format('M d, Y H:i:s') }}</td>
                        <td class="td text-ink-500 dark:text-[#8a9087] tnum">{{ $backup['size_human'] }}</td>
                        <td class="td text-right">
                            <a href="{{ route('reports.registry.backup.download', $backup['name']) }}"
                               class="btn btn-ghost text-[11px] px-2 py-1">
                                <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" /> Download
                            </a>
                            <div x-data="{ open: false }" class="inline-block">
                                <button type="button" @click="open = true"
                                        class="btn btn-ghost text-[11px] px-2 py-1 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                    Delete
                                </button>
                                <form x-ref="delForm" method="POST" action="{{ route('reports.registry.backup.destroy', $backup['name']) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <x-confirm-modal show="open"
                                                 title="Delete this backup?"
                                                 confirm="$refs.delForm.submit()"
                                                 confirm-label="Delete permanently">
                                    <p>The backup file <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $backup['name'] }}</strong> will be permanently removed.</p>
                                </x-confirm-modal>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="td text-center py-12 text-ink-400">No backups yet. Click <strong>Create Backup Now</strong> above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-doc-footer signatory="OSCA Officer / Reviewer" />
</div>
@endsection
