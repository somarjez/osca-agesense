@php
    $steps = [
        ['n' => '01', 'title' => 'Collect & Prepare', 'body' => 'Senior citizen records and survey responses are cleaned, validated, and encoded.'],
        ['n' => '02', 'title' => 'Profile', 'body' => 'Machine learning identifies groups of senior citizens with similar multidimensional characteristics.'],
        ['n' => '03', 'title' => 'Estimate', 'body' => 'AgeSense estimates internally referenced possible-risk indicators for decision-support purposes.'],
        ['n' => '04', 'title' => 'Explain', 'body' => 'Important characteristics and contributing indicators behind each result are surfaced.'],
        ['n' => '05', 'title' => 'Contextualize', 'body' => 'GIS and service-accessibility information add environmental context where applicable.'],
        ['n' => '06', 'title' => 'Recommend', 'body' => 'Profile groups, indicators, and WHO-domain patterns become prioritized recommendations for human review.'],
    ];
@endphp
<section id="how-it-works" class="scroll-mt-20 py-24 lg:py-32 bg-white">
    <div class="shell">
        <div class="reveal-up max-w-2xl">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-ink-900 text-balance">
                From Senior Citizen Information to Decision Support
            </h2>
        </div>

        {{-- Desktop: one long connected process --}}
        <div id="workflow-line" class="reveal-up hidden lg:block mt-20 relative group/wf">
            <div class="absolute left-0 right-0 top-[22px] h-[2px] bg-paper-rule overflow-hidden" aria-hidden="true">
                <div class="h-full bg-accent-500 origin-left scale-x-0 transition-transform duration-[1400ms] ease-out [.is-active_&]:scale-x-100"></div>
            </div>
            <div class="relative grid grid-cols-6 gap-4">
                @foreach ($steps as $step)
                    <div class="flex flex-col items-center text-center">
                        <div class="w-11 h-11 rounded-full bg-white border-2 border-accent-500 text-accent-700 font-serif font-semibold text-[14px] flex items-center justify-center shadow-sm transition-colors duration-200 hover:bg-accent-600 hover:text-white">
                            {{ $step['n'] }}
                        </div>
                        <div class="font-serif text-[14px] font-semibold text-ink-900 mt-3.5">{{ $step['title'] }}</div>
                        <p class="text-[11.5px] leading-relaxed text-ink-500 mt-1.5 max-w-[9.5rem]">{{ $step['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Mobile / tablet: vertical timeline --}}
        <div class="reveal-up lg:hidden mt-12 space-y-7">
            @foreach ($steps as $step)
                <div class="relative pl-12 {{ !$loop->last ? 'pb-2' : '' }}">
                    @unless ($loop->last)
                        <div class="absolute left-[19px] top-10 bottom-0 w-px bg-paper-rule" aria-hidden="true"></div>
                    @endunless
                    <div class="absolute left-0 top-0 w-10 h-10 rounded-full bg-white border-2 border-accent-500 text-accent-700 font-serif font-semibold text-[13px] flex items-center justify-center">
                        {{ $step['n'] }}
                    </div>
                    <div class="font-serif text-[15px] font-semibold text-ink-900">{{ $step['title'] }}</div>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-1">{{ $step['body'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Workflow summary strip --}}
        <div class="reveal-up mt-16 pt-10 border-t border-paper-rule flex flex-wrap items-center justify-center gap-x-3 gap-y-3">
            @foreach (['Data', 'Analysis', 'Understanding', 'Decision Support'] as $i => $word)
                <span class="text-[12px] sm:text-[13px] font-semibold uppercase tracking-[0.1em] {{ $i === 3 ? 'text-accent-700' : 'text-ink-400' }}">{{ $word }}</span>
                @if (!$loop->last)
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                @endif
            @endforeach
        </div>
    </div>
</section>
