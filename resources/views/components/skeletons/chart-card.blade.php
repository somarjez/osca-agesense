@props(['height' => 'h-56', 'span' => ''])
<div class="card {{ $span }} animate-pulse" aria-busy="true" aria-live="polite">
    <div class="card-head">
        <div>
            <div class="h-3.5 w-32 rounded bg-paper-rule dark:bg-[#2b3530] mb-2"></div>
            <div class="h-2.5 w-40 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
        </div>
    </div>
    <div class="card-body">
        <div class="{{ $height }} rounded-xl bg-paper-rule/40 dark:bg-[#2b3530]/40"></div>
    </div>
</div>
