@props(['type' => 'info', 'title' => null])
@php
    $styles = [
        'success' => 'bg-low-50 border-low-100 text-low-700 dark:bg-low-700/20 dark:border-low-100/30 dark:text-low-100',
        'error'   => 'bg-high-50 border-high-100 text-high-700 dark:bg-high-700/20 dark:border-high-100/30 dark:text-high-100',
        'warning' => 'bg-moderate-50 border-moderate-100 text-moderate-700 dark:bg-moderate-700/20 dark:border-moderate-100/30 dark:text-moderate-100',
        'info'    => 'bg-info-100 border-info-100 text-info-700 dark:bg-info-700/20 dark:border-info-100/30 dark:text-info-100',
    ];
@endphp
<div role="alert" class="rounded-xl border px-4 py-3 {{ $styles[$type] ?? $styles['info'] }}">
    @if ($title)
        <p class="font-semibold text-sm mb-0.5">{{ $title }}</p>
    @endif
    <div class="text-sm leading-relaxed">{{ $slot }}</div>
</div>
