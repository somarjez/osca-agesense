{{--
    Reusable horizontal stepper for multi-step survey wizards (New Profile, QoL
    Survey). Purely presentational — navigation is wired by the caller via the
    `click` method name (defaults to the `goToStep(int $step)` convention both
    survey components already implement, so no new Livewire methods are needed).

    @props
      steps   array  [n => ['label' => string, 'sub' => string|null]] — 1-indexed
      current int    the active step number
      total   int    total step count
      click   string Livewire method to call with the target step, e.g. "goToStep"
--}}
@props([
    'steps' => [],
    'current' => 1,
    'total' => 1,
    'click' => 'goToStep',
])
<nav aria-label="Form steps" class="mb-5">
    {{-- Full stepper — hidden on mobile in favor of the compact row below.
         flex-wrap + a min-width per button lets 8-step forms (QoL) wrap onto a
         second row instead of squeezing unreadably, without needing a second
         component variant for shorter (6-step) forms. --}}
    <div class="hidden md:flex flex-wrap gap-1.5 mb-2" role="list">
        @foreach ($steps as $n => $s)
        @php $state = $n < $current ? 'done' : ($n === $current ? 'current' : 'todo'); @endphp
        <button type="button" wire:click="{{ $click }}({{ $n }})"
                role="listitem"
                @if ($state === 'current') aria-current="step" @endif
                class="flex-1 min-w-[120px] flex items-center gap-2 py-2 px-2.5 rounded-lg text-left transition-all
                       focus:outline-none focus:ring-2 focus:ring-accent-500/40
                       {{ $state === 'current'
                          ? 'bg-accent-600 text-white shadow-sm'
                          : ($state === 'done'
                              ? 'bg-accent-50 dark:bg-accent-900/20 text-accent-700 dark:text-accent-400 hover:bg-accent-100 dark:hover:bg-accent-900/30'
                              : 'bg-paper-2 dark:bg-[#202a26] text-ink-500 dark:text-[#6b7570] hover:bg-paper-rule dark:hover:bg-[#2b3530]') }}">
            <span class="flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center text-[10.5px] font-bold
                         {{ $state === 'current'
                             ? 'bg-white/20'
                             : ($state === 'done' ? 'bg-accent-600 text-white' : 'bg-paper-rule dark:bg-[#2b3530]') }}">
                @if ($state === 'done')
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                @else
                    {{ $n }}
                @endif
            </span>
            <span class="min-w-0 truncate">
                <span class="block text-xs font-semibold leading-tight truncate">{{ $s['label'] ?? '' }}</span>
                @if (!empty($s['sub']))
                <span class="block text-[10.5px] font-normal leading-tight truncate {{ $state === 'current' ? 'text-white/70' : 'opacity-70' }}">
                    {{ $s['sub'] }}
                </span>
                @endif
            </span>
        </button>
        @endforeach
    </div>

    {{-- Compact row for mobile — avoids squeezing N buttons into a narrow viewport. --}}
    <div class="flex md:hidden items-center justify-between mb-2 gap-2">
        <span class="text-xs font-semibold text-ink-700 dark:text-[#c8c4bc] flex-shrink-0">Step {{ $current }} of {{ $total }}</span>
        <span class="text-xs text-ink-500 dark:text-[#6b7570] truncate">{{ $steps[$current]['label'] ?? '' }}</span>
    </div>

    @php $progressPct = $total > 1 ? ((($current - 1) / ($total - 1)) * 100) : 100; @endphp
    <div class="w-full bg-paper-rule dark:bg-[#2b3530] rounded-full h-1">
        <div class="bg-accent-500 h-1 rounded-full transition-all duration-500 motion-reduce:transition-none"
             style="width: {{ $progressPct }}%"></div>
    </div>
</nav>
