@php
    $items = [
        [
            'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            'kicker' => 'Multidimensional',
            'title' => 'Senior Citizen Profiling',
        ],
        [
            'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z',
            'kicker' => 'Explainable',
            'title' => 'Machine Learning Insights',
        ],
        [
            'icon' => 'M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            'kicker' => 'Location-Aware',
            'title' => 'GIS &amp; Accessibility Context',
        ],
        [
            'icon' => 'M9 12.75 11.25 15 15 9.75m6 2.25a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            'kicker' => 'Action-Oriented',
            'title' => 'Decision-Support Recommendations',
        ],
    ];
@endphp
<section class="border-y border-paper-rule bg-white">
    <div class="shell grid grid-cols-2 lg:grid-cols-4 divide-x divide-paper-rule">
        @foreach ($items as $i => $item)
            <div class="reveal-up flex items-center gap-3 py-8 px-4 sm:px-6 {{ $i >= 2 ? 'border-t lg:border-t-0 border-paper-rule' : '' }}">
                <div class="w-10 h-10 rounded-xl bg-accent-50 text-accent-700 flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                    </svg>
                </div>
                <div>
                    <div class="eyebrow">{{ $item['kicker'] }}</div>
                    <div class="font-serif text-[14.5px] sm:text-[15.5px] font-semibold text-ink-900 leading-snug mt-0.5">{!! $item['title'] !!}</div>
                </div>
            </div>
        @endforeach
    </div>
</section>
