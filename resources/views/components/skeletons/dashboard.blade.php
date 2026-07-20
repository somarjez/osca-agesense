<div class="space-y-7" aria-busy="true" aria-live="polite">

    {{-- Page header --}}
    <div class="animate-pulse">
        <div class="h-6 w-40 rounded bg-paper-rule dark:bg-[#2b3530] mb-2"></div>
        <div class="h-3 w-64 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
    </div>

    {{-- KPIs --}}
    <x-skeletons.stat-cards :count="4" />

    {{-- Filter bar --}}
    <div class="flex flex-wrap items-center gap-3 animate-pulse">
        <div class="h-3 w-10 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
        <div class="h-8 w-[180px] rounded-lg bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
        <div class="h-8 w-[160px] rounded-lg bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
    </div>

    {{-- Bento grid — mirrors livewire/dashboard/main-dashboard.blade.php card order/sizes --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5 items-stretch">
        <x-skeletons.chart-card height="h-56" />
        <x-skeletons.chart-card height="h-56" />
        <x-skeletons.chart-card height="h-52" />

        {{-- Urgent Pending Actions / Recent Senior Records list cards --}}
        @for ($i = 0; $i < 2; $i++)
            <div class="card animate-pulse" aria-busy="true" aria-live="polite">
                <div class="card-head">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-7 h-7 rounded-lg bg-paper-rule dark:bg-[#2b3530] flex-shrink-0"></span>
                        <div class="min-w-0 space-y-1.5">
                            <div class="h-3 w-32 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
                            <div class="h-2.5 w-24 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
                        </div>
                    </div>
                </div>
                <div class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                    @for ($r = 0; $r < 5; $r++)
                        <div class="px-4 py-3 flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-paper-rule/60 dark:bg-[#2b3530]/60 flex-shrink-0"></div>
                            <div class="flex-1 space-y-1.5">
                                <div class="h-3 w-2/3 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
                                <div class="h-2.5 w-1/3 rounded bg-paper-rule/40 dark:bg-[#2b3530]/40"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        @endfor

        <x-skeletons.chart-card height="h-60" />

        {{-- Wellbeing gauge --}}
        <div class="card animate-pulse" aria-busy="true" aria-live="polite">
            <div class="text-center p-4">
                <div class="h-3.5 w-32 rounded bg-paper-rule dark:bg-[#2b3530] mx-auto mb-2"></div>
                <div class="h-2.5 w-48 rounded bg-paper-rule/60 dark:bg-[#2b3530]/60 mx-auto mb-3"></div>
                <div class="h-[150px] w-full max-w-[240px] mx-auto rounded-xl bg-paper-rule/40 dark:bg-[#2b3530]/40"></div>
            </div>
        </div>

        {{-- Barangay Breakdown — spans 2 columns --}}
        <div class="card xl:col-span-2 animate-pulse" aria-busy="true" aria-live="polite">
            <div class="card-head">
                <div class="h-3.5 w-40 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
            </div>
            <div class="card-body grid grid-cols-1 xl:grid-cols-2 gap-x-8 gap-y-3">
                @for ($i = 0; $i < 8; $i++)
                    <div class="h-3 rounded-full bg-paper-rule/50 dark:bg-[#2b3530]/50"></div>
                @endfor
            </div>
        </div>
    </div>
</div>
