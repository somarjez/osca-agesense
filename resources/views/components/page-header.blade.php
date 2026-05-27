@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div class="mb-6">
    @if ($eyebrow)
        <p class="eyebrow mb-1.5">{{ $eyebrow }}</p>
    @endif
    <h1 class="font-serif text-[26px] font-semibold tracking-snug text-ink-900 dark:text-[#e4e1d8] leading-tight">
        {{ $title }}
    </h1>
    @if ($subtitle)
        <p class="text-[13px] text-ink-500 dark:text-[#6b7570] mt-0.5 leading-relaxed">{{ $subtitle }}</p>
    @endif
</div>
