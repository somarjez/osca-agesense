@props(['office' => 'Office of Senior Citizens Affairs'])
{{-- Republic-of-the-Philippines band: the top-of-shell government attribution strip. --}}
<div {{ $attributes->merge(['class' => 'gov-band']) }}>
    <span class="gov-band-title">Republic of the Philippines</span>
    <span class="gov-band-sep" aria-hidden="true"></span>
    <span class="gov-band-sub">Province of Laguna &middot; Municipality of Pagsanjan</span>
    <span class="gov-band-sub ml-auto hidden md:inline-block">{{ $office }}</span>
</div>
