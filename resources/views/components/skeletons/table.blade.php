@props(['rows' => 5, 'cols' => 4])
<div class="card animate-pulse" aria-busy="true" aria-live="polite">
    <div class="card-head">
        <div class="h-3.5 w-40 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
    </div>
    <div class="divide-y divide-paper-rule dark:divide-[#2b3530]">
        @for ($r = 0; $r < $rows; $r++)
            <div class="px-4 py-3 flex items-center gap-4">
                @for ($c = 0; $c < $cols; $c++)
                    <div class="h-3 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60 flex-1"></div>
                @endfor
            </div>
        @endfor
    </div>
</div>
