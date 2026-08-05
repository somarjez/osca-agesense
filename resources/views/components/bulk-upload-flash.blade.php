{{--
    Bulk Upload Success / Error Flash — shared by seniors/index.blade.php and
    ml/batch.blade.php. A separate component from <x-toast /> because it
    carries a richer payload (a message plus an optional expandable list of
    skipped-row errors) that the generic single-message toast doesn't support.
    Bulk upload now redirects to the Batch Assessment page when it queued ML
    jobs (see BulkUploadController::upload()), and to the senior list
    otherwise — both need to show this same flash.
--}}
@if (session('bulk_success'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)"
     class="fixed bottom-5 right-5 z-50 max-w-sm w-full card shadow-xl border-l-4 border-forest-500 flex items-start gap-3 px-4 py-3">
    <x-heroicon-o-check-circle class="w-5 h-5 text-forest-600 flex-shrink-0 mt-0.5" />
    <div class="flex-1 min-w-0">
        <p class="text-[13px] font-semibold text-ink-900">Import complete</p>
        <p class="text-[12px] text-ink-600 mt-0.5">{{ session('bulk_success') }}</p>
        @if (session('bulk_errors'))
        <details class="mt-2">
            <summary class="text-[11.5px] text-ink-400 cursor-pointer hover:text-ink-600">Show skipped rows</summary>
            <ul class="mt-1 space-y-0.5">
                @foreach (session('bulk_errors') as $err)
                <li class="text-[11px] text-high-700">{{ $err }}</li>
                @endforeach
            </ul>
        </details>
        @endif
    </div>
    <button @click="show = false" class="text-ink-300 hover:text-ink-600 flex-shrink-0">
        <x-heroicon-o-x-mark class="w-4 h-4" />
    </button>
</div>
@endif
