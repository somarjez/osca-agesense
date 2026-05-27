@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div class="mb-6">
    @if ($eyebrow)
        <p class="eyebrow mb-1.5">{{ $eyebrow }}</p>
    @endif
    <h1 class="text-xl font-bold text-ink-900 dark:text-ink-100 leading-tight tracking-tight">
        {{ $title }}
    </h1>
    @if ($subtitle)
        <p class="text-sm text-ink-500 dark:text-ink-400 mt-0.5 leading-relaxed">{{ $subtitle }}</p>
    @endif
</div>
