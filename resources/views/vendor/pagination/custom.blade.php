@php
$current = $paginator->currentPage();
$last    = $paginator->lastPage();

// Sliding 10-page window centered on current page
$start = max(1, min($current - 4, $last - 9));
$end   = min($last, $start + 9);
@endphp

@if ($paginator->hasPages())
<div class="flex flex-col items-center gap-2 text-sm py-1">

    {{-- Results count --}}
    <p class="text-xs text-slate-500">
        Showing
        <span class="font-semibold text-slate-700">{{ $paginator->firstItem() }}</span>
        –
        <span class="font-semibold text-slate-700">{{ $paginator->lastItem() }}</span>
        of
        <span class="font-semibold text-slate-700">{{ number_format($paginator->total()) }}</span>
        results
    </p>

    {{-- Page buttons --}}
    <div class="flex items-center justify-center gap-1 flex-wrap">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="px-2.5 py-1.5 text-xs rounded-lg text-slate-300 border border-slate-200 select-none cursor-not-allowed">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}"
               class="px-2.5 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-slate-50 hover:border-slate-300 transition-colors">‹</a>
        @endif

        {{-- First page anchor + leading ellipsis --}}
        @if ($start > 1)
            <a href="{{ $paginator->url(1) }}"
               class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">1</a>
            @if ($start > 2)
                <span class="px-2 py-1.5 text-xs text-slate-400 select-none">…</span>
            @endif
        @endif

        {{-- Sliding window (up to 10 pages) --}}
        @for ($page = $start; $page <= $end; $page++)
            @if ($page == $current)
                <span class="px-3 py-1.5 text-xs rounded-lg bg-teal-600 text-white font-semibold select-none shadow-sm">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}"
                   class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">{{ $page }}</a>
            @endif
        @endfor

        {{-- Trailing ellipsis + last page anchor --}}
        @if ($end < $last)
            @if ($end < $last - 1)
                <span class="px-2 py-1.5 text-xs text-slate-400 select-none">…</span>
            @endif
            <a href="{{ $paginator->url($last) }}"
               class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">{{ $last }}</a>
        @endif

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}"
               class="px-2.5 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-slate-50 hover:border-slate-300 transition-colors">›</a>
        @else
            <span class="px-2.5 py-1.5 text-xs rounded-lg text-slate-300 border border-slate-200 select-none cursor-not-allowed">›</span>
        @endif

    </div>
</div>
@endif
