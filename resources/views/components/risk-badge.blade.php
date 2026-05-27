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
    {{ $lvl ?: '—' }}
    @if ($isUrgent)
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
             class="w-3 h-3 ml-0.5 inline-block" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
        </svg>
        <span class="sr-only">urgent</span>
    @endif
</span>
