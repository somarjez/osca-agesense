@props([
    'label',
    'value' => null,
])

<div>
    <span class="text-xs font-medium text-slate-500 uppercase">{{ $label }}</span>
    <div class="mt-0.5 text-slate-800 font-medium">
        @if (blank($value))
            <span class="text-slate-400">N/A</span>
        @else
            {{ $value }}
        @endif
    </div>
</div>
