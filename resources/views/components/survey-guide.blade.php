{{--
    Right-rail guide card for multi-step survey wizards. One card with internal
    divided sections (not nested cards): identity/intro, a completion ring, and
    a Helpful Tips list. No "Need Assistance / Contact Support" block — the
    surveys don't have a support channel wired up, so this rail never implies one.

    @props
      title        string
      intro        string|null
      percent      int          0-100, caller-computed (see completionPercent())
      label        string       eyebrow above the ring — default "Required Fields"
                                 fits a caller whose percent genuinely tracks
                                 required-field completion (QolSurveyForm); a
                                 caller whose percent instead tracks wizard
                                 position (ProfileSurvey) should pass "Progress"
      stepCaption  string|null  e.g. "Step 2 of 6"
      statusText   string|null  e.g. "In progress."
      tips         array<string>
--}}
@props([
    'title' => 'Guide',
    'intro' => null,
    'percent' => 0,
    'label' => 'Required Fields',
    'stepCaption' => null,
    'statusText' => null,
    'tips' => [],
])
@php
    $pct = max(0, min(100, (int) $percent));
    $radius = 26;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $pct / 100);
@endphp
<aside class="card xl:sticky xl:top-6 h-fit" aria-label="{{ $title }}">
    <div class="card-body space-y-5">
        <div>
            <div class="flex items-center gap-2 mb-1.5">
                <x-heroicon-o-information-circle class="w-4 h-4 text-accent-600 dark:text-accent-400 flex-shrink-0" aria-hidden="true" />
                <h2 class="card-title">{{ $title }}</h2>
            </div>
            @if ($intro)
            <p class="text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">{{ $intro }}</p>
            @endif
        </div>

        <div class="pt-4 border-t border-paper-rule dark:border-[#2b3530]">
            <p class="eyebrow mb-3">{{ $label }}</p>
            <div class="flex items-center gap-4">
                <div class="relative w-16 h-16 flex-shrink-0" role="img" aria-label="{{ $pct }}% — {{ $label }}">
                    <svg viewBox="0 0 60 60" class="w-16 h-16 -rotate-90">
                        <circle cx="30" cy="30" r="{{ $radius }}" fill="none" stroke-width="5"
                                stroke="currentColor" class="text-paper-2 dark:text-[#202a26]" />
                        <circle cx="30" cy="30" r="{{ $radius }}" fill="none" stroke-width="5" stroke-linecap="round"
                                stroke="currentColor" class="text-accent-600 dark:text-accent-400 transition-[stroke-dashoffset] duration-500 motion-reduce:transition-none"
                                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}" />
                    </svg>
                    <span class="absolute inset-0 flex items-center justify-center text-[13px] font-bold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $pct }}%</span>
                </div>
                <div class="min-w-0">
                    @if ($stepCaption)
                    <p class="text-[12.5px] font-semibold text-ink-800 dark:text-[#c8c4bc]">{{ $stepCaption }}</p>
                    @endif
                    @if ($statusText)
                    <p class="text-[11.5px] text-ink-500 dark:text-[#8a9087] mt-0.5">{{ $statusText }}</p>
                    @endif
                </div>
            </div>
        </div>

        @if (count($tips))
        <div class="pt-4 border-t border-paper-rule dark:border-[#2b3530]">
            <p class="eyebrow mb-2.5 flex items-center gap-1.5">
                <x-heroicon-o-light-bulb class="w-3.5 h-3.5 text-accent-600 dark:text-accent-400 flex-shrink-0" aria-hidden="true" />
                Helpful Tips
            </p>
            <ul class="space-y-2">
                @foreach ($tips as $tip)
                <li class="flex items-start gap-2 text-[12px] text-ink-600 dark:text-[#a8ada4] leading-relaxed">
                    <x-heroicon-o-check-circle class="w-3.5 h-3.5 text-low-500 flex-shrink-0 mt-0.5" aria-hidden="true" />
                    <span>{{ $tip }}</span>
                </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
</aside>
