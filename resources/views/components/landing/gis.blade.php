@php
    $points = [
        ['x' => 18, 'y' => 28, 'tone' => 'accent-500'],
        ['x' => 34, 'y' => 55, 'tone' => 'accent-500'],
        ['x' => 52, 'y' => 22, 'tone' => 'high-500'],
        ['x' => 61, 'y' => 48, 'tone' => 'accent-500'],
        ['x' => 74, 'y' => 68, 'tone' => 'moderate-500'],
        ['x' => 82, 'y' => 32, 'tone' => 'accent-500'],
        ['x' => 44, 'y' => 74, 'tone' => 'accent-500'],
    ];
@endphp
<section class="py-24 lg:py-32 bg-white">
    <div class="shell grid lg:grid-cols-[1.1fr_0.9fr] gap-14 lg:gap-16 items-center">
        <div class="reveal-up relative aspect-[4/3] rounded-2xl bg-paper-2 border border-paper-rule overflow-hidden" aria-hidden="true">
            <div class="absolute inset-0 opacity-[0.5]" style="background-image: linear-gradient(#e8e4d6 1px, transparent 1px), linear-gradient(90deg, #e8e4d6 1px, transparent 1px); background-size: 34px 34px;"></div>
            @foreach ($points as $p)
                <span class="absolute w-3 h-3 rounded-full bg-{{ $p['tone'] }} ring-4 ring-white shadow-sm" style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%; transform: translate(-50%,-50%);"></span>
            @endforeach
            <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between rounded-xl bg-white/90 backdrop-blur border border-paper-rule px-4 py-2.5 text-[11.5px] font-medium text-ink-600">
                <span>Barangay service points &middot; illustrative</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-accent-500"></span>Service access</span>
            </div>
        </div>

        <div class="reveal-up">
            <h2 class="font-serif text-[30px] sm:text-[38px] font-semibold tracking-snug text-ink-900 text-balance">
                Location Adds Context.
            </h2>
            <p class="mt-5 text-[15px] leading-relaxed text-ink-600">
                AgeSense uses GIS and service accessibility as supporting environmental context alongside a senior citizen's profile &mdash; not as a standalone system.
            </p>

            <ul class="mt-7 space-y-3">
                @foreach (['Healthcare facilities', 'Community services', 'Barangay context', 'Service proximity', 'Accessibility information'] as $x)
                    <li class="flex items-center gap-3 text-[14px] text-ink-700">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-accent-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                        {{ $x }}
                    </li>
                @endforeach
            </ul>

            <p class="mt-7 text-[12.5px] leading-relaxed text-ink-500 border-l-2 border-paper-rule pl-4">
                GIS outputs provide descriptive accessibility context and are not population-level barangay rankings.
            </p>
        </div>
    </div>
</section>
