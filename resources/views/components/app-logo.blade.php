@props(['size' => 32])
{{--
  AgeSense application mark. A serif "A" monogram on an institutional navy tile,
  crossed by a forest "ledger rule" — pairing the two brand colours (navy chrome
  + forest accent) and nodding to the system's Field-Ledger identity.
  Decorative beside the wordmark, so aria-hidden by default.
--}}
<svg {{ $attributes->merge(['class' => 'flex-shrink-0']) }}
     width="{{ $size }}" height="{{ $size }}"
     viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"
     role="img" aria-label="AgeSense">
    <rect width="40" height="40" rx="11" fill="#16213a"/>
    {{-- subtle top highlight so the tile reads as a crafted object, not a flat block --}}
    <rect x="0.6" y="0.6" width="38.8" height="38.8" rx="10.5" stroke="#ffffff" stroke-opacity="0.07"/>
    {{-- A — legs in paper --}}
    <path d="M20 8.4 L12 31.6" stroke="#fbfaf6" stroke-width="2.5" stroke-linecap="round"/>
    <path d="M20 8.4 L28 31.6" stroke="#fbfaf6" stroke-width="2.5" stroke-linecap="round"/>
    {{-- crossbar / ledger rule in the accent blue (the one action accent) --}}
    <path d="M14.7 24.3 L25.3 24.3" stroke="#5689d6" stroke-width="2.5" stroke-linecap="round"/>
</svg>
