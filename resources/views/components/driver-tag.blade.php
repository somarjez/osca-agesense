@props(['up' => false])
{{-- Unambiguous direction badge for XAI risk drivers.
     up = raises risk (red "Risk factor"); !up = lowers risk (green "Protective").
     Word + colour + glyph all encode the same meaning so it can't be misread. --}}
@php
    $cls = $up
        ? 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-900'
        : 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-900';
    $label = $up ? 'Risk factor' : 'Protective';
    $glyph = $up ? '▲' : '▼';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold leading-none whitespace-nowrap $cls"]) }}>
    <span aria-hidden="true">{{ $glyph }}</span>{{ $label }}
</span>
