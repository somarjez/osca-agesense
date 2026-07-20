@props(['count' => 4])
<div class="grid grid-cols-2 lg:grid-cols-{{ $count }} gap-4" aria-busy="true" aria-live="polite">
    @for ($i = 0; $i < $count; $i++)
        <div class="kpi animate-pulse">
            <div class="kpi-rule bg-paper-rule dark:bg-[#2b3530]"></div>
            <div class="h-3 w-20 rounded bg-paper-rule dark:bg-[#2b3530] mb-3"></div>
            <div class="h-7 w-16 rounded bg-paper-rule/80 dark:bg-[#2b3530]/80 mb-2"></div>
            <div class="h-2.5 w-24 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
        </div>
    @endfor
</div>
