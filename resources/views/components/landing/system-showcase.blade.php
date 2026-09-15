<section class="relative py-24 lg:py-32 bg-navy-900 text-paper overflow-hidden">
    <div class="absolute inset-0 dot-field text-white opacity-[0.04] pointer-events-none" aria-hidden="true"></div>
    <div class="glow-orb top-0 right-0 w-[30rem] h-[30rem] bg-accent-700/20" aria-hidden="true"></div>

    <div class="shell relative">
        <div class="reveal-up max-w-2xl">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-balance">
                One Platform. Multiple Perspectives.
            </h2>
            <p class="mt-5 text-[15px] leading-relaxed text-navy-200">
                Profiling, possible-risk indication, explainable insights, accessibility context, and recommendations come together in a single workspace built for authorized OSCA and LGU personnel &mdash; not a disconnected set of tools.
            </p>
        </div>

        {{-- ── Composite interface preview — built from the system's own design
             tokens, not a real screenshot, so no senior-citizen data is at risk. ── --}}
        <div class="reveal-up mt-14 lg:mt-16 mx-auto max-w-[1180px]" aria-hidden="true">
            <div class="browser-frame">
                <div class="browser-frame-bar">
                    <span class="browser-frame-dot"></span><span class="browser-frame-dot"></span><span class="browser-frame-dot"></span>
                    <div class="ml-3 text-[11px] text-navy-300 font-mono truncate">agesense.local/dashboard</div>
                </div>
                <div class="bg-paper-2 p-4 sm:p-6 lg:p-7">
                    <div class="grid grid-cols-[52px_1fr] sm:grid-cols-[180px_1fr] gap-4 lg:gap-6">
                        {{-- mini sidebar --}}
                        <div class="bg-white rounded-xl border border-paper-rule p-2.5 sm:p-3 space-y-1">
                            @foreach ([
                                ['label' => 'Dashboard', 'active' => true],
                                ['label' => 'Senior Records', 'active' => false],
                                ['label' => 'Profile Groups', 'active' => false],
                                ['label' => 'GIS Analytics', 'active' => false],
                                ['label' => 'Recommendations', 'active' => false],
                            ] as $item)
                                <div class="flex items-center gap-2 px-2 py-2 rounded-lg text-[11.5px] font-medium {{ $item['active'] ? 'bg-navy-800 text-paper' : 'text-ink-500' }}">
                                    <span class="w-4 h-4 rounded-[5px] flex-shrink-0 {{ $item['active'] ? 'bg-accent-400' : 'bg-paper-2 border border-paper-rule' }}"></span>
                                    <span class="hidden sm:inline truncate">{{ $item['label'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        {{-- mini content --}}
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="font-serif text-[15px] font-semibold text-ink-900">Dashboard</div>
                                <span class="badge badge-neutral">Sample Data</span>
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                @foreach ([['Total Profiles','1,240'], ['Priority Cases','86'], ['Avg. Wellbeing','71']] as [$label, $value])
                                    <div class="kpi py-2.5 px-3">
                                        <div class="kpi-label text-[9px]">{{ $label }}</div>
                                        <div class="kpi-value text-[20px] mt-0.5">{{ $value }}</div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="grid sm:grid-cols-[auto_1fr] gap-3">
                                <div class="card">
                                    <div class="card-body flex items-center gap-4 py-4">
                                        <div class="relative w-20 h-20 rounded-full flex-shrink-0" style="background: conic-gradient(#4a8a68 0% 45%, #c19a3b 45% 75%, #e0621a 75% 100%)">
                                            <div class="absolute inset-[7px] rounded-full bg-white flex items-center justify-center text-[10px] font-semibold text-ink-700">1,240</div>
                                        </div>
                                        <div class="space-y-1.5 text-[10.5px]">
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-low-500"></span>Low</div>
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-moderate-500"></span>Moderate</div>
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-high-500"></span>High</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card">
                                    <div class="card-body py-4 space-y-2.5">
                                        @foreach ([['Thriving',62,'low'],['Stable',48,'moderate'],['At-Risk',30,'high']] as [$g,$w,$tone])
                                            <div class="flex items-center gap-2.5">
                                                <span class="text-[10.5px] text-ink-600 w-14 flex-shrink-0">{{ $g }}</span>
                                                <div class="bar"><div class="bar-fill bar-fill-{{ $tone }}" style="width: {{ $w }}%"></div></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Feature callouts describing the preview above --}}
        <div class="reveal-up mt-10 flex flex-wrap justify-center gap-x-8 gap-y-3 text-[12.5px] font-medium text-navy-200">
            @foreach (['Profile Discovery', 'Possible-Risk Analysis', 'Explainable Factors', 'GIS Context', 'Recommendation Support', 'Reporting'] as $label)
                <span class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-accent-400"></span>{{ $label }}
                </span>
            @endforeach
        </div>
    </div>
</section>
