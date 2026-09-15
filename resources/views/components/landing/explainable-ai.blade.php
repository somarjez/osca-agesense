<section class="py-24 lg:py-32 bg-paper-2">
    <div class="shell">
        <div class="reveal-up max-w-2xl">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-ink-900 text-balance">
                Not Just a Result.<br>A Reason You Can Review.
            </h2>
            <p class="mt-5 text-[15px] leading-relaxed text-ink-600">
                AgeSense does not present machine-learning outputs as unexplained labels. Authorized users can review relevant profile characteristics, WHO-domain summaries, contributing indicators, and plain-language interpretation.
            </p>
        </div>

        {{-- Result → Contributing Indicators → Interpretation → Human Review --}}
        <div class="reveal-up mt-14 grid lg:grid-cols-[auto_auto_auto_auto] gap-4 items-stretch max-w-5xl mx-auto">
            <div class="card p-5 flex flex-col items-center justify-center text-center bg-navy-900 border-navy-900">
                <div class="eyebrow text-navy-300">Possible Risk Indicator</div>
                <div class="font-serif text-[22px] font-semibold text-paper mt-1.5">Moderate</div>
            </div>

            <div class="hidden lg:flex items-center justify-center text-ink-300" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </div>

            <div class="card p-5">
                <div class="eyebrow mb-2">Contributing Indicators</div>
                <ul class="space-y-1.5 text-[12.5px] text-ink-700">
                    @foreach (['Healthcare access', 'Financial capacity', 'Functional independence', 'Social support', 'Environmental conditions'] as $x)
                        <li class="flex items-center gap-2"><span class="w-1 h-1 rounded-full bg-accent-500 flex-shrink-0"></span>{{ $x }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="hidden lg:flex items-center justify-center text-ink-300" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </div>

            <div class="card p-5 flex flex-col items-center justify-center text-center bg-accent-50 border-accent-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-accent-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
                <div class="font-serif text-[14px] font-semibold text-ink-900 mt-2">Plain-Language Interpretation</div>
            </div>
        </div>

        <div class="reveal-up flex justify-center my-4" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-ink-300 rotate-90 lg:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25 12 15.75 4.5 8.25"/></svg>
        </div>

        <div class="reveal-up max-w-sm mx-auto card p-5 text-center border-navy-200">
            <div class="font-serif text-[15px] font-semibold text-navy-800">Review &amp; Human Decision</div>
            <p class="text-[12px] text-ink-500 mt-1">Final decisions remain with authorized personnel and appropriate professionals.</p>
        </div>

        <div class="reveal-up mt-16 grid sm:grid-cols-3 gap-6 max-w-4xl mx-auto text-center">
            @foreach ([
                ['title' => 'See the Result', 'body' => 'Users can see the information associated with a generated result.'],
                ['title' => 'Understand the Basis', 'body' => 'Technical outputs are translated into information intended for authorized OSCA and LGU personnel.'],
                ['title' => 'Review Before Action', 'body' => 'Analytical results support review and planning while final decisions remain with authorized personnel.'],
            ] as $point)
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.1em] text-accent-700">{{ $point['title'] }}</div>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-2">{{ $point['body'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
