@props(['level', 'priority' => null])
@php
$lvl = strtoupper($level ?? '');
// CRITICAL is no longer an official level — remap to HIGH for display safety
if ($lvl === 'CRITICAL') { $lvl = 'HIGH'; }
$class = match($lvl) {
    'HIGH'     => 'badge-high',
    'MODERATE' => 'badge-moderate',
    'LOW'      => 'badge-low',
    default    => 'badge-neutral',
};
$isUrgent = ($priority === 'urgent');
@endphp
<span class="badge {{ $class }} {{ $isUrgent ? 'ring-1 ring-orange-400' : '' }}">
    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
    {{ $lvl ?: '—' }}{{ $isUrgent ? ' ⚠' : '' }}
</span>
