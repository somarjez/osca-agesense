<section id="stakeholders" class="scroll-mt-20 py-24 lg:py-32 bg-white">
    <div class="shell">
        <div class="reveal-up max-w-2xl">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-ink-900 text-balance">
                Built to Support the People Behind Senior Citizen Programs.
            </h2>
        </div>

        <div class="mt-14 grid lg:grid-cols-[1.4fr_1fr] gap-6 items-stretch">
            {{-- Main stakeholders — larger, distinct panel --}}
            <div class="reveal-up rounded-2xl bg-navy-900 text-paper p-9 sm:p-12 flex flex-col justify-between">
                <div>
                    <div class="eyebrow text-navy-300">Primary Stakeholders</div>
                    <h3 class="font-serif text-[26px] sm:text-[30px] font-semibold tracking-snug mt-2 text-balance">
                        OSCA &amp; LGU Personnel
                    </h3>
                    <p class="text-[14.5px] leading-relaxed text-navy-200 mt-4 max-w-md">
                        Turn multidimensional records into information that can support review, prioritization, and program planning.
                    </p>
                </div>

                <div class="mt-10 grid sm:grid-cols-2 gap-5 pt-6 border-t border-navy-700">
                    <div>
                        <div class="font-serif text-[14.5px] font-semibold">Office for Senior Citizens Affairs</div>
                        <p class="text-[12.5px] text-navy-300 mt-1 leading-relaxed">Supports organized review of senior citizen information and possible priorities.</p>
                    </div>
                    <div>
                        <div class="font-serif text-[14.5px] font-semibold">Local Government Units</div>
                        <p class="text-[12.5px] text-navy-300 mt-1 leading-relaxed">Assists local planning, policy discussion, and resource prioritization.</p>
                    </div>
                </div>
            </div>

            {{-- Supporting roles --}}
            <div class="reveal-up rounded-2xl border border-paper-rule p-2 flex flex-col divide-y divide-paper-rule">
                @foreach ([
                    [
                        'icon' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772',
                        'title' => 'Social Workers & Program Implementers',
                        'body' => 'Additional context for reviewing household, social, functional, and financial concerns.',
                    ],
                    [
                        'icon' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733C11.285 4.876 9.623 3.75 7.688 3.75 5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z',
                        'title' => 'Health Workers',
                        'body' => 'Supplementary context that may support referral and review, where authorized.',
                    ],
                    [
                        'icon' => 'M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                        'title' => 'Senior Citizens',
                        'body' => 'May indirectly benefit from more organized, data-informed local planning.',
                    ],
                ] as $s)
                    <div class="flex items-start gap-3.5 p-5">
                        <div class="w-9 h-9 rounded-lg bg-accent-50 text-accent-700 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}"/>
                            </svg>
                        </div>
                        <div>
                            <div class="font-serif text-[13.5px] font-semibold text-ink-900 leading-snug">{{ $s['title'] }}</div>
                            <p class="text-[12px] leading-relaxed text-ink-500 mt-1">{{ $s['body'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
