@props(['level'])
@php
$lvl = strtoupper($level ?? '');
$class = match($lvl) {
    'CRITICAL' => 'badge-critical',
    'HIGH'     => 'badge-high',
    'MODERATE' => 'badge-moderate',
    'LOW'      => 'badge-low',
    default    => 'badge-neutral',
};
@endphp
<span class="badge {{ $class }}">
    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
    {{ $lvl ?: '—' }}
</span>
