<div class="space-y-4 animate-pulse" aria-busy="true" aria-live="polite">
    <div class="h-6 w-48 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @for ($i = 0; $i < 4; $i++)
            <div class="h-24 rounded-xl bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
        @endfor
    </div>
    <div class="h-64 rounded-xl bg-paper-rule/40 dark:bg-[#2b3530]/40"></div>
</div>
