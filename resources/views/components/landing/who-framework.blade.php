<section id="who-framework" class="scroll-mt-20 relative py-24 lg:py-32 overflow-hidden bg-gradient-to-b from-accent-50/50 to-paper-2">
    <div class="absolute inset-0 dot-field text-navy-900 opacity-[0.04] pointer-events-none" aria-hidden="true"></div>

    <div class="shell relative">
        <div class="reveal-up max-w-2xl mx-auto text-center">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-ink-900 text-balance">
                One Framework. A More Complete View of Healthy Ageing.
            </h2>
            <p class="mt-5 text-[15px] leading-relaxed text-ink-600">
                AgeSense organizes senior citizen information using the multidimensional perspective of healthy ageing &mdash; the relationship between personal capacity, the surrounding environment, and the ability to function in daily life.
            </p>
        </div>

        {{-- Interconnected diagram: two source domains converging on Functional Ability --}}
        <div class="reveal-up mt-16 grid lg:grid-cols-[1fr_auto_1fr] gap-8 lg:gap-4 items-center max-w-5xl mx-auto">
            <div class="rounded-2xl bg-white border border-paper-rule shadow-sm p-7">
                <div class="w-11 h-11 rounded-xl chip-3d bg-accent-50 text-accent-700 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733C11.285 4.876 9.623 3.75 7.688 3.75 5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>
                    </svg>
                </div>
                <h3 class="font-serif text-[19px] font-semibold text-ink-900 mt-4">Intrinsic Capacity</h3>
                <p class="text-[13px] text-ink-500 mt-1.5 leading-relaxed">Physical and mental capacities that influence an older adult's overall functioning and well-being.</p>
                <ul class="mt-4 space-y-1.5 text-[12.5px] text-ink-600">
                    @foreach (['Physical capacity', 'Mental well-being', 'Mobility', 'Sensory condition'] as $x)
                        <li class="flex items-center gap-2">
                            <span class="w-1 h-1 rounded-full bg-accent-500 flex-shrink-0"></span>{{ $x }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex lg:flex-col items-center justify-center gap-2 text-accent-400" aria-hidden="true">
                <span class="hidden lg:block w-10 h-px bg-accent-300"></span>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 rotate-90 lg:rotate-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                <span class="hidden lg:block w-10 h-px bg-accent-300"></span>
            </div>

            <div class="rounded-2xl bg-white border border-paper-rule shadow-sm p-7">
                <div class="w-11 h-11 rounded-xl chip-3d bg-accent-50 text-accent-700 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>
                    </svg>
                </div>
                <h3 class="font-serif text-[19px] font-semibold text-ink-900 mt-4">Environment</h3>
                <p class="text-[13px] text-ink-500 mt-1.5 leading-relaxed">Social, economic, service-related, household, and physical conditions that may support or limit daily living.</p>
                <ul class="mt-4 space-y-1.5 text-[12.5px] text-ink-600">
                    @foreach (['Social support', 'Financial security', 'Home environment', 'Healthcare access', 'Community services'] as $x)
                        <li class="flex items-center gap-2">
                            <span class="w-1 h-1 rounded-full bg-accent-500 flex-shrink-0"></span>{{ $x }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="reveal-up flex flex-col items-center gap-1.5 my-3" aria-hidden="true">
            <span class="w-px h-8 bg-accent-300"></span>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-accent-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25 12 15.75 4.5 8.25"/></svg>
        </div>

        <div class="reveal-up max-w-xl mx-auto rounded-2xl bg-navy-900 text-paper shadow-lg p-8 text-center">
            <div class="w-12 h-12 rounded-xl bg-white/10 mx-auto flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-accent-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3M3 3v18"/>
                </svg>
            </div>
            <h3 class="font-serif text-[20px] font-semibold mt-4">Functional Ability</h3>
            <p class="text-[13.5px] text-navy-200 mt-2 leading-relaxed max-w-md mx-auto">
                What an older adult is able to be and do, through the interaction between personal capacity and the surrounding environment &mdash; not something independent of the two.
            </p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                @foreach (['Independence', 'Daily activities', 'Participation', 'Meeting personal needs'] as $x)
                    <span class="text-[11px] font-medium px-2.5 py-1 rounded-full bg-white/10 text-navy-100">{{ $x }}</span>
                @endforeach
            </div>
        </div>

        <p class="reveal-up mt-12 text-center text-[13px] text-ink-500 max-w-xl mx-auto">
            AgeSense uses these domains as an analytical and interpretive structure for understanding the multidimensional conditions of participating senior citizens.
        </p>
    </div>
</section>
