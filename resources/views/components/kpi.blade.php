@props([
    'label',
    'value',
    'accent' => 'forest',  // forest|critical|high|moderate|low|info
    'sub' => null,
    'valueColor' => null,  // optional override e.g. 'text-critical-700'
    'loadingTarget' => null,  // wire:target propert(ies)/method(s); renders a per-card <x-loading-overlay> when set — see .kpi's `relative` in app.css
])
@php
$ruleClass = match($accent) {
    'critical' => 'bg-critical-500',
    'high'     => 'bg-high-500',
    'moderate' => 'bg-moderate-500',
    'low'      => 'bg-low-500',
    'info'     => 'bg-info-500',
    default    => 'bg-forest-600',
};
$accentClass = 'kpi--' . $accent;
@endphp
<div class="kpi {{ $accentClass }}">
    @if ($loadingTarget)
        <x-loading-overlay :target="$loadingTarget" />
    @endif
    <div class="kpi-rule {{ $ruleClass }}"></div>
    <div class="kpi-label">{{ $label }}</div>
    <div class="kpi-value {{ $valueColor }}" data-countup>{{ $value }}</div>
    @if ($sub)
        <div class="kpi-delta">{{ $sub }}</div>
    @elseif (isset($delta))
        <div class="kpi-delta">{{ $delta }}</div>
    @endif
</div>
