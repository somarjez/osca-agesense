@php
    $categories = [
        ['label' => 'Health', 'x' => 50.0, 'y' => 10.0],
        ['label' => 'Functional Ability', 'x' => 81.3, 'y' => 25.1],
        ['label' => 'Environment', 'x' => 89.0, 'y' => 58.9],
        ['label' => 'Social Support', 'x' => 67.4, 'y' => 86.0],
        ['label' => 'Financial Condition', 'x' => 32.6, 'y' => 86.0],
        ['label' => 'Quality of Life', 'x' => 11.0, 'y' => 58.9],
        ['label' => 'Service Accessibility', 'x' => 18.7, 'y' => 25.1],
    ];
@endphp
<section id="about" class="scroll-mt-20 py-24 lg:py-32 bg-white overflow-hidden">
    <div class="shell grid lg:grid-cols-2 gap-16 lg:gap-10 items-center">
        <div class="reveal-up">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug leading-[1.1] text-ink-900 text-balance">
                Senior Citizen Records Tell Only Part of the Story.
            </h2>
            <p class="mt-6 text-[15.5px] leading-relaxed text-ink-600 max-w-lg">
                Health conditions, functional ability, socioeconomic conditions, environmental support, social participation, quality of life, and access to services all provide context that basic demographic records alone cannot. AgeSense examines these dimensions of ageing together, rather than in isolation.
            </p>

            <div class="mt-10 divide-y divide-paper-rule max-w-lg">
                @foreach ([
                    ['title' => 'Multidimensional Profiling', 'body' => 'Examines health, socioeconomic, functional, environmental, psychosocial, and quality-of-life indicators together.'],
                    ['title' => 'Explainable Analysis', 'body' => 'Presents understandable profile characteristics and contributing indicators instead of unexplained machine learning results.'],
                    ['title' => 'Decision Support', 'body' => 'Translates analytical results into prioritized recommendations that remain subject to human review.'],
                ] as $row)
                    <div class="flex items-start gap-4 py-4 first:pt-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-1 flex-shrink-0 text-accent-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3"/>
                        </svg>
                        <div>
                            <div class="font-serif text-[15.5px] font-semibold text-ink-900">{{ $row['title'] }}</div>
                            <p class="text-[13.5px] leading-relaxed text-ink-600 mt-1">{{ $row['body'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Layered category diagram converging on a single ageing profile ── --}}
        <div class="reveal-up" aria-hidden="true">
            <div class="relative aspect-square max-w-md mx-auto">
                <svg class="absolute inset-0 w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                    @foreach ($categories as $c)
                        <line x1="50" y1="50" x2="{{ $c['x'] }}" y2="{{ $c['y'] }}" stroke="#c9d6e8" stroke-width="0.5" />
                    @endforeach
                </svg>

                @foreach ($categories as $c)
                    <div class="absolute -translate-x-1/2 -translate-y-1/2 bg-white border border-paper-rule shadow-sm rounded-full px-3 py-1.5 text-[10.5px] sm:text-[11px] font-semibold text-ink-700 whitespace-nowrap"
                         style="left: {{ $c['x'] }}%; top: {{ $c['y'] }}%;">
                        {{ $c['label'] }}
                    </div>
                @endforeach

                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-28 h-28 sm:w-32 sm:h-32 rounded-full bg-navy-900 text-paper shadow-md flex items-center justify-center text-center px-3">
                    <span class="font-serif text-[12px] sm:text-[13px] font-semibold uppercase tracking-[0.08em] leading-snug">Ageing Profile</span>
                </div>
            </div>

            <p class="mt-8 text-center text-[13px] font-medium text-ink-500">
                Fragmented information <span class="text-accent-600 mx-1">&rarr;</span> multidimensional understanding
            </p>
        </div>
    </div>
</section>
