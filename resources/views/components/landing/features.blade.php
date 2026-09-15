<section id="features" class="scroll-mt-20 py-24 lg:py-32 bg-paper-2">
    <div class="shell">
        <div class="reveal-up max-w-2xl">
            <h2 class="font-serif text-[32px] sm:text-[42px] font-semibold tracking-snug text-ink-900 text-balance">
                Designed for Data-Informed Senior Citizen Support
            </h2>
        </div>

        <div class="mt-14 grid md:grid-cols-2 gap-5">
            {{-- Senior Citizen Profiling — tall --}}
            <div class="reveal-up card card-lift min-h-[240px]">
                <div class="card-body flex flex-col h-full">
                    <div class="flex items-center gap-2.5 mb-2">
                        @foreach (['bg-cluster-1','bg-cluster-2','bg-cluster-3','bg-cluster-4'] as $c)
                            <span class="w-8 h-8 rounded-lg {{ $c }} opacity-90"></span>
                        @endforeach
                    </div>
                    <h3 class="font-serif text-[17px] font-semibold text-ink-900 mt-2">Senior Citizen Profiling</h3>
                    <p class="text-[13.5px] leading-relaxed text-ink-600 mt-2">Organizes participating senior citizens into meaningful multidimensional profile groups based on shared characteristics.</p>
                </div>
            </div>

            {{-- Possible Risk Indicators — tall --}}
            <div class="reveal-up card card-lift min-h-[240px]">
                <div class="card-body flex flex-col h-full">
                    <div class="flex rounded-full overflow-hidden h-2.5 mb-2">
                        <span class="bg-low-500 w-1/3"></span><span class="bg-moderate-500 w-1/3"></span><span class="bg-high-500 w-1/3"></span>
                    </div>
                    <span class="badge badge-moderate w-fit">Moderate</span>
                    <h3 class="font-serif text-[17px] font-semibold text-ink-900 mt-3">Possible Risk Indicators</h3>
                    <p class="text-[13.5px] leading-relaxed text-ink-600 mt-2">Internally referenced possible-risk levels that help authorized users identify records that may warrant closer review.</p>
                </div>
            </div>

            {{-- WHO Domain Analysis — normal --}}
            <div class="reveal-up card card-lift">
                <div class="card-body">
                    <div class="space-y-1.5 mb-3">
                        @foreach ([70,55,62] as $w)
                            <div class="bar"><div class="bar-fill bar-fill-forest" style="width: {{ $w }}%"></div></div>
                        @endforeach
                    </div>
                    <h3 class="font-serif text-[15.5px] font-semibold text-ink-900">WHO Domain Analysis</h3>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-1.5">Summarizes indicators related to Intrinsic Capacity, Environment, and Functional Ability.</p>
                </div>
            </div>

            {{-- Explainable ML — normal --}}
            <div class="reveal-up card card-lift">
                <div class="card-body">
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach (['Access','Support','Capacity'] as $tag)
                            <span class="badge badge-neutral">{{ $tag }}</span>
                        @endforeach
                    </div>
                    <h3 class="font-serif text-[15.5px] font-semibold text-ink-900">Explainable Machine Learning</h3>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-1.5">Presents understandable factors and profile characteristics behind analytical outputs.</p>
                </div>
            </div>

            {{-- GIS & Accessibility — tall --}}
            <div class="reveal-up card card-lift min-h-[240px]">
                <div class="card-body flex flex-col h-full">
                    <div class="relative w-full h-16 rounded-lg bg-paper-2 border border-paper-rule overflow-hidden mb-2">
                        <span class="absolute w-1.5 h-1.5 rounded-full bg-accent-500" style="left:22%;top:35%"></span>
                        <span class="absolute w-1.5 h-1.5 rounded-full bg-accent-500" style="left:48%;top:60%"></span>
                        <span class="absolute w-1.5 h-1.5 rounded-full bg-high-500" style="left:68%;top:30%"></span>
                        <span class="absolute w-1.5 h-1.5 rounded-full bg-accent-500" style="left:80%;top:65%"></span>
                    </div>
                    <h3 class="font-serif text-[17px] font-semibold text-ink-900 mt-1">GIS &amp; Service Accessibility</h3>
                    <p class="text-[13.5px] leading-relaxed text-ink-600 mt-2">Supporting location and service-proximity context for represented senior citizens.</p>
                </div>
            </div>

            {{-- Prescriptive Recommendations — tall --}}
            <div class="reveal-up card card-lift min-h-[240px]">
                <div class="card-body flex flex-col h-full">
                    <ul class="space-y-2 mb-2">
                        @foreach ([['Refer for wellness check-in','high'],['Review healthcare access','moderate'],['Confirm service enrollment','low']] as [$t,$tone])
                            <li class="flex items-center gap-2 text-[12px] text-ink-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-{{ $tone }}-500 flex-shrink-0"></span>{{ $t }}
                            </li>
                        @endforeach
                    </ul>
                    <h3 class="font-serif text-[17px] font-semibold text-ink-900 mt-1">Prescriptive Recommendations</h3>
                    <p class="text-[13.5px] leading-relaxed text-ink-600 mt-2">Prioritized decision-support suggestions related to services, referrals, programs, and healthy-ageing support.</p>
                </div>
            </div>

            {{-- Reports & Analytics — normal --}}
            <div class="reveal-up card card-lift">
                <div class="card-body">
                    <div class="flex items-end gap-1.5 h-10 mb-3">
                        @foreach ([40,65,50,80,60] as $h)
                            <span class="w-2.5 rounded-t bg-navy-700" style="height: {{ $h }}%"></span>
                        @endforeach
                    </div>
                    <h3 class="font-serif text-[15.5px] font-semibold text-ink-900">Reports &amp; Analytics</h3>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-1.5">Summarized information that can support local planning, review, and program development.</p>
                </div>
            </div>

            {{-- Senior Citizen Records — normal --}}
            <div class="reveal-up card card-lift">
                <div class="card-body">
                    <div class="space-y-1.5 mb-3">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="flex items-center gap-2">
                                <span class="w-5 h-5 rounded-full bg-paper-2 border border-paper-rule flex-shrink-0"></span>
                                <span class="h-2 rounded bg-paper-2 border border-paper-rule flex-1"></span>
                            </div>
                        @endfor
                    </div>
                    <h3 class="font-serif text-[15.5px] font-semibold text-ink-900">Senior Citizen Records</h3>
                    <p class="text-[13px] leading-relaxed text-ink-600 mt-1.5">Organized management and review of senior citizen information.</p>
                </div>
            </div>
        </div>
    </div>
</section>
