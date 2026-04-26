@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}
$scrollSnippet = ($scrollTo !== false)
    ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView();"
    : '';

$current  = $paginator->currentPage();
$last     = $paginator->lastPage();
$pageName = $paginator->getPageName();

// Sliding 10-page window centered on current page
$start = max(1, min($current - 4, $last - 9));
$end   = min($last, $start + 9);
@endphp

<div>
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
                <button type="button"
                        wire:click="previousPage('{{ $pageName }}')"
                        wire:loading.attr="disabled"
                        x-on:click="{{ $scrollSnippet }}"
                        class="px-2.5 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-slate-50 hover:border-slate-300 transition-colors">‹</button>
            @endif

            {{-- First page anchor + leading ellipsis --}}
            @if ($start > 1)
                <button type="button"
                        wire:click="gotoPage(1, '{{ $pageName }}')"
                        wire:loading.attr="disabled"
                        x-on:click="{{ $scrollSnippet }}"
                        class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">1</button>
                @if ($start > 2)
                    <span class="px-2 py-1.5 text-xs text-slate-400 select-none">…</span>
                @endif
            @endif

            {{-- Sliding window (up to 10 pages) --}}
            @for ($page = $start; $page <= $end; $page++)
                @if ($page == $current)
                    <span wire:key="paginator-{{ $pageName }}-page{{ $page }}"
                          class="px-3 py-1.5 text-xs rounded-lg bg-teal-600 text-white font-semibold select-none shadow-sm">{{ $page }}</span>
                @else
                    <button type="button"
                            wire:key="paginator-{{ $pageName }}-page{{ $page }}"
                            wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                            wire:loading.attr="disabled"
                            x-on:click="{{ $scrollSnippet }}"
                            class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">{{ $page }}</button>
                @endif
            @endfor

            {{-- Trailing ellipsis + last page anchor --}}
            @if ($end < $last)
                @if ($end < $last - 1)
                    <span class="px-2 py-1.5 text-xs text-slate-400 select-none">…</span>
                @endif
                <button type="button"
                        wire:click="gotoPage({{ $last }}, '{{ $pageName }}')"
                        wire:loading.attr="disabled"
                        x-on:click="{{ $scrollSnippet }}"
                        class="px-3 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-teal-50 hover:text-teal-700 hover:border-teal-200 transition-colors">{{ $last }}</button>
            @endif

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <button type="button"
                        wire:click="nextPage('{{ $pageName }}')"
                        wire:loading.attr="disabled"
                        x-on:click="{{ $scrollSnippet }}"
                        class="px-2.5 py-1.5 text-xs rounded-lg text-slate-600 border border-slate-200 hover:bg-slate-50 hover:border-slate-300 transition-colors">›</button>
            @else
                <span class="px-2.5 py-1.5 text-xs rounded-lg text-slate-300 border border-slate-200 select-none cursor-not-allowed">›</span>
            @endif

        </div>
    </div>
    @endif
</div>
