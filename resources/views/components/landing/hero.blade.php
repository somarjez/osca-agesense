<section id="home" class="relative min-h-[90vh] flex items-center overflow-hidden -mt-[76px] pt-[76px] bg-gradient-to-b from-accent-50/40 via-paper to-paper">
    <div class="absolute inset-0 dot-field text-navy-900 opacity-[0.05] pointer-events-none" aria-hidden="true"></div>
    <div class="glow-orb -top-32 -right-16 w-[34rem] h-[34rem] bg-accent-200/50" aria-hidden="true"></div>
    <div class="glow-orb bottom-0 -left-24 w-[24rem] h-[24rem] bg-navy-100/60" aria-hidden="true"></div>

    <div class="shell relative grid lg:grid-cols-[0.95fr_1.15fr] gap-14 lg:gap-10 items-center py-14 lg:py-20 w-full">
        <div class="reveal-up">
            <span class="framework-tag">WHO Healthy Ageing &middot; Explainable ML &middot; Decision Support</span>

            <h1 class="font-serif font-semibold tracking-snug leading-[1.04] text-ink-900 text-balance mt-7 text-[38px] sm:text-[52px] lg:text-[66px]">
                Understanding Ageing.<br>
                <span class="text-accent-700">Supporting Better Decisions.</span>
            </h1>

            <p class="mt-6 text-[16px] sm:text-[17px] leading-relaxed text-ink-600 max-w-xl">
                AgeSense transforms multidimensional senior citizen information into understandable profiles, possible-risk indicators, explainable insights, accessibility context, and decision-support recommendations for authorized OSCA and LGU personnel.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                <a href="{{ route('login') }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary group text-[14.5px] px-6 py-3.5 shadow-sm">
                    Access AgeSense
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 ease-out group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </a>
                <a href="#how-it-works" class="btn btn-secondary group text-[14.5px] px-6 py-3.5">
                    Explore Platform
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 ease-out group-hover:translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25 12 15.75 4.5 8.25"/>
                    </svg>
                </a>
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-[12.5px] font-medium text-ink-500">
                <span>Human-reviewed</span>
                <span class="w-1 h-1 rounded-full bg-ink-300" aria-hidden="true"></span>
                <span>Explainable</span>
                <span class="w-1 h-1 rounded-full bg-ink-300" aria-hidden="true"></span>
                <span>Community-focused</span>
            </div>
        </div>

        {{-- ── Composite system preview — illustrative only, never real records ── --}}
        <div class="reveal-up relative" aria-hidden="true">
            <div class="relative max-w-xl mx-auto lg:mx-0 lg:ml-auto py-6">
                <div class="float-slow card shadow-xl border-paper-rule/80 relative z-10">
                    <div class="card-head">
                        <div>
                            <div class="card-title">AgeSense Healthy Ageing Overview</div>
                            <div class="card-sub">Sample Senior Profile &middot; illustrative data only</div>
                        </div>
                        <span class="badge badge-neutral">Profile Group: Stable</span>
                    </div>
                    <div class="card-body space-y-5">
                        <div>
                            <div class="eyebrow mb-2">WHO Domain Overview</div>
                            <div class="space-y-2.5">
                                @foreach ([
                                    ['Intrinsic Capacity', 72, 'low'],
                                    ['Environment', 58, 'moderate'],
                                    ['Functional Ability', 65, 'moderate'],
                                ] as [$domain, $pct, $tone])
                                    <div>
                                        <div class="flex items-center justify-between text-[12px] text-ink-600 mb-1">
                                            <span>{{ $domain }}</span>
                                            <span class="tnum text-ink-400">{{ $pct }}%</span>
                                        </div>
                                        <div class="bar">
                                            <div class="bar-fill bar-fill-{{ $tone }}" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="pt-4 border-t border-paper-rule flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Possible Risk Indicator</div>
                                <span class="badge badge-moderate mt-1.5">Moderate</span>
                            </div>
                            <div class="text-right">
                                <div class="eyebrow">Recommendation Priority</div>
                                <span class="badge badge-info mt-1.5">Review Soon</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Floating callout cards — layered depth around the main panel --}}
                <div class="float-slower hidden sm:block absolute -top-6 -left-6 z-20 card shadow-lg px-3.5 py-3 w-44">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg chip-3d bg-accent-50 text-accent-700 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
                        </div>
                        <div class="text-[11.5px] font-semibold text-ink-800 leading-snug">Explainable Factors</div>
                    </div>
                </div>

                <div class="float-slow hidden sm:block absolute -bottom-7 -right-5 z-20 card shadow-lg px-3.5 py-3 w-48">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg chip-3d bg-low-50 text-low-700 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </div>
                        <div class="text-[11.5px] font-semibold text-ink-800 leading-snug">Service Accessibility</div>
                    </div>
                </div>

                <div class="float-slower hidden lg:block absolute top-1/3 -right-10 z-20 card shadow-lg px-3.5 py-3 w-40">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg chip-3d bg-navy-50 text-navy-700 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m6 2.25a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </div>
                        <div class="text-[11.5px] font-semibold text-ink-800 leading-snug">Profile Analysis</div>
                    </div>
                </div>

                <div class="float-slow hidden lg:block absolute -bottom-10 left-8 z-0 card shadow-lg px-3.5 py-3 w-48 opacity-90">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg chip-3d bg-moderate-50 text-moderate-700 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733C11.285 4.876 9.623 3.75 7.688 3.75 5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/></svg>
                        </div>
                        <div class="text-[11.5px] font-semibold text-ink-800 leading-snug">Healthy Ageing Support</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
