@props(['value' => 0, 'showNumber' => true])
@php
$pct = round(($value ?? 0) * 100);
// 3-level thresholds: HIGH>=0.50, MODERATE>=0.30, LOW<0.30
// Bar color uses HIGH shade for urgent-range (>=0.70) too — no separate critical color.
$fill = $value >= 0.50 ? 'bar-fill-high'
      : ($value >= 0.30 ? 'bar-fill-moderate' : 'bar-fill-low');
@endphp
<div class="flex items-center gap-2">
    <div class="bar flex-1"><div class="bar-fill {{ $fill }}" style="width: {{ $pct }}%"></div></div>
    @if ($showNumber)
        <span class="font-mono text-[11.5px] font-semibold text-ink-900 tnum w-11 text-right">{{ number_format($value, 3) }}</span>
    @endif
</div>
