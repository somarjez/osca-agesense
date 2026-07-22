{{--
    Per-step section header shared by the survey wizards — a numbered tile plus
    title/description, replacing the plain <h3> each step used to open with.

    @props
      step      int|null
      title     string
      desc      string|null
      required  bool  shows "* Required fields" on the right when true
--}}
@props([
    'step' => null,
    'title' => '',
    'desc' => null,
    'required' => true,
])
<div class="flex items-start justify-between gap-4 mb-5">
    <div class="flex items-start gap-3 min-w-0">
        @if ($step)
        <div class="w-9 h-9 rounded-xl bg-accent-50 dark:bg-accent-900/30 text-accent-700 dark:text-accent-400
                    flex items-center justify-center flex-shrink-0 font-serif font-semibold chip-3d" aria-hidden="true">
            {{ $step }}
        </div>
        @endif
        <div class="min-w-0">
            <h3 class="font-display text-xl text-ink-800 dark:text-[#e4e1d8] leading-tight">{{ $title }}</h3>
            @if ($desc)
            <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] mt-0.5">{{ $desc }}</p>
            @endif
        </div>
    </div>
    @if ($required)
    <span class="text-[11px] text-ink-400 dark:text-[#6b7570] flex-shrink-0 whitespace-nowrap mt-1.5">
        <span class="text-critical-700" aria-hidden="true">*</span> Required fields
    </span>
    @endif
</div>
