{{-- resources/views/livewire/surveys/qol-survey-form.blade.php --}}
<div class="max-w-6xl mx-auto space-y-5" x-data="{ scale: [
    { value: 1, label: 'Strongly Disagree' },
    { value: 2, label: 'Disagree' },
    { value: 3, label: 'Neither' },
    { value: 4, label: 'Agree' },
    { value: 5, label: 'Strongly Agree' }
]}">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records', 'href' => route('seniors.index')],
        ['label' => $senior->full_name, 'href' => route('seniors.show', $senior)],
        ['label' => 'QoL Survey'],
    ]" />
    <x-page-header
        eyebrow="Quality of Life Survey"
        :title="$senior->full_name"
        :subtitle="'Step ' . $step . ' of ' . $totalSteps"
    />

    {{-- ── Back button ── --}}
    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to {{ $senior->full_name }}
    </a>

    {{-- ── Senior Banner ── --}}
    <div class="card">
        <div class="card-body flex items-center gap-4">
            <div class="w-10 h-10 rounded-xl bg-forest-100 dark:bg-forest-900/40 flex items-center justify-center flex-shrink-0">
                <span class="font-bold text-forest-700 dark:text-forest-300 text-[15px]">{{ strtoupper(substr($senior->first_name, 0, 1)) }}</span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</p>
                <p class="text-[12.5px] text-ink-500 dark:text-[#6b7570]">{{ $senior->barangay }} · Age {{ $senior->age }} · System ID: {{ $senior->osca_id }}</p>
            </div>
            <div class="flex-shrink-0">
                <label class="eyebrow block mb-1">Survey Date</label>
                <input type="date" wire:model="surveyDate"
                       class="form-input py-1.5 text-[13px] w-auto">
            </div>
        </div>
    </div>

    @php
    $sections = [
        1 => 'A. Overall QoL',
        2 => 'B. Physical',
        3 => 'C. Psychological',
        4 => 'D. Independence',
        5 => 'E. Social',
        6 => 'F. Environment',
        7 => 'G. Financial',
        8 => 'H. Spirituality',
    ];
    $progress = $this->getSectionProgress();
    @endphp

    {{-- ── Step Progress ── --}}
    <x-survey-stepper :steps="array_map(fn ($l) => ['label' => $l], $sections)" :current="$step" :total="$totalSteps" click="goToStep" />

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">

        {{-- ── Question Card ── --}}
        <div>
        <form wire:submit.prevent="{{ $step < $totalSteps ? 'nextStep' : 'confirmSubmit' }}">
        <div class="card overflow-hidden">

            {{-- Instructions header (step 1 only) --}}
            @if ($step === 1)
            <div class="bg-forest-50 dark:bg-forest-900/20 border-b border-forest-100 dark:border-forest-800/30 px-5 py-3">
                <p class="text-[13px] text-forest-800 dark:text-forest-300">
                    <strong>Instructions:</strong> In the past four weeks, how true is each statement for you?
                    Rate each item from <strong>1 (Strongly Disagree)</strong> to <strong>5 (Strongly Agree)</strong>.
                </p>
            </div>
            @endif

            {{-- Validation errors --}}
            @if ($errors->any())
            <div class="px-5 py-3 bg-moderate-50 dark:bg-moderate-500/10 border-b border-moderate-100 dark:border-moderate-500/20">
                <p class="text-[13px] font-medium text-moderate-700 dark:text-moderate-500">
                    Please answer all questions in <strong>{{ $sections[$step] ?? 'this section' }}</strong> before continuing.
                    ({{ $errors->count() }} {{ Str::plural('question', $errors->count()) }} unanswered.)
                </p>
            </div>
            @endif

            <div class="p-5">

                {{-- Section title --}}
                <x-survey-section-head :step="$step" :title="$sections[$step]"
                    :desc="$step === 8 ? 'Optional — you may skip this section.' : null"
                    :required="$step !== 8" />

                {{-- Scale legend --}}
                <div class="grid grid-cols-5 gap-1 mb-6 text-center">
                    @foreach ([1 => 'Strongly Disagree', 2 => 'Disagree', 3 => 'Neither', 4 => 'Agree', 5 => 'Strongly Agree'] as $v => $lbl)
                    <div class="text-[11px] text-ink-500 dark:text-[#6b7570]">
                        <div class="font-bold text-ink-700 dark:text-[#c8c4bc] text-[13px]">{{ $v }}</div>
                        {{ $lbl }}
                    </div>
                    @endforeach
                </div>

                {{-- Questions per section --}}
                @php
                $questions = match($step) {
                    1 => [
                        'a1' => ['item' => 'A1', 'text' => 'I enjoy my life overall.', 'reverse' => false],
                        'a2' => ['item' => 'A2', 'text' => 'I feel satisfied with my life at present.', 'reverse' => false],
                        'a3' => ['item' => 'A3', 'text' => 'I look forward to things in the future.', 'reverse' => false],
                        'a4' => ['item' => 'A4', 'text' => 'I feel that my life is meaningful.', 'reverse' => false],
                    ],
                    2 => [
                        'b1' => ['item' => 'B1', 'text' => 'I have enough physical energy for daily activities.', 'reverse' => false],
                        'b2' => ['item' => 'B2', 'text' => 'Pain or discomfort affects my well-being.', 'reverse' => true],
                        'b3' => ['item' => 'B3', 'text' => 'My health limits me in taking care of myself (e.g., bathing, dressing).', 'reverse' => true],
                        'b4' => ['item' => 'B4', 'text' => 'My health allows me to go outside my home when I want to.', 'reverse' => false],
                        'b5' => ['item' => 'B5', 'text' => 'I can move around (walk, climb stairs) without much difficulty.', 'reverse' => false],
                    ],
                    3 => [
                        'c1' => ['item' => 'C1', 'text' => 'I feel happy most of the time.', 'reverse' => false],
                        'c2' => ['item' => 'C2', 'text' => 'I feel calm and at peace with myself.', 'reverse' => false],
                        'c3' => ['item' => 'C3', 'text' => 'I often feel lonely.', 'reverse' => true],
                        'c4' => ['item' => 'C4', 'text' => 'I feel confident in handling problems in my life.', 'reverse' => false],
                    ],
                    4 => [
                        'd1' => ['item' => 'D1', 'text' => 'I feel independent in doing the things I need to do every day.', 'reverse' => false],
                        'd2' => ['item' => 'D2', 'text' => 'I can decide for myself how to spend my time.', 'reverse' => false],
                        'd3' => ['item' => 'D3', 'text' => 'I have control over the important things in my life.', 'reverse' => false],
                        'd4' => ['item' => 'D4', 'text' => 'My income or pension limits the things I want to do.', 'reverse' => true],
                    ],
                    5 => [
                        'e1' => ['item' => 'E1', 'text' => 'I have family or friends I can rely on when I need help.', 'reverse' => false],
                        'e2' => ['item' => 'E2', 'text' => 'I feel close to at least one person (family member, friend, or neighbor).', 'reverse' => false],
                        'e3' => ['item' => 'E3', 'text' => 'I have opportunities to join social or community activities if I want to.', 'reverse' => false],
                        'e4' => ['item' => 'E4', 'text' => 'I participate in social, religious, or community activities that I enjoy.', 'reverse' => false],
                        'e5' => ['item' => 'E5', 'text' => 'I feel respected by my family and community.', 'reverse' => false],
                    ],
                    6 => [
                        'f1' => ['item' => 'F1', 'text' => 'I feel safe in my home.', 'reverse' => false],
                        'f2' => ['item' => 'F2', 'text' => 'I feel safe in my neighborhood.', 'reverse' => false],
                        'f3' => ['item' => 'F3', 'text' => 'Local shops and services (e.g., market, health center, church) are easy for me to reach.', 'reverse' => false],
                        'f4' => ['item' => 'F4', 'text' => 'My home is comfortable and suitable for my needs.', 'reverse' => false],
                    ],
                    7 => [
                        'g1' => ['item' => 'G1', 'text' => 'I have enough money to pay for my regular household expenses (water, electricity, food).', 'reverse' => false],
                        'g2' => ['item' => 'G2', 'text' => 'I can afford necessary medical care and medicines.', 'reverse' => false],
                        'g3' => ['item' => 'G3', 'text' => 'I can afford to buy small things I want for myself (e.g., clothes, personal items).', 'reverse' => false],
                    ],
                    8 => [
                        'h1' => ['item' => 'H1', 'text' => 'My religious or spiritual beliefs give me comfort and strength.', 'reverse' => false],
                        'h2' => ['item' => 'H2', 'text' => 'I can practice my religion or spiritual beliefs in the way I want.', 'reverse' => false],
                    ],
                    default => [],
                };
                @endphp

                <div class="space-y-5">
                    @foreach ($questions as $prop => $q)
                    <div wire:key="qol-question-{{ $prop }}"
                         class="border rounded-xl p-4 transition-colors
                         {{ $errors->has($prop)
                             ? 'border-moderate-300 bg-moderate-50 dark:border-moderate-500/30 dark:bg-moderate-500/10'
                             : 'border-paper-rule dark:border-[#2b3530] hover:border-forest-200 dark:hover:border-forest-700/50' }}"
                         x-data="{ value: $wire.entangle('{{ $prop }}') }">
                        <div class="flex items-start gap-3 mb-3">
                            <span class="flex-shrink-0 w-8 h-8 rounded-full bg-paper-2 dark:bg-[#202a26] flex items-center justify-center text-xs font-bold text-ink-600 dark:text-[#8a9087]">
                                {{ $q['item'] }}
                            </span>
                            <p class="text-[13.5px] text-ink-800 dark:text-[#c8c4bc] leading-relaxed">
                                {{ $q['text'] }}
                                @if ($q['reverse'])
                                <span class="ml-1 text-[11px] text-moderate-600 dark:text-moderate-500 font-medium">(↺ reverse)</span>
                                @endif
                            </p>
                        </div>
                        <div class="grid grid-cols-5 gap-2">
                            @foreach ([1,2,3,4,5] as $val)
                            <button type="button"
                                    @click="value = (value === {{ $val }} ? null : {{ $val }})"
                                    :aria-pressed="(value === {{ $val }}).toString()"
                                    :class="value === {{ $val }}
                                        ? 'border-forest-500 bg-forest-600 text-white ring-2 ring-forest-400 ring-offset-2 ring-offset-paper dark:ring-offset-[#1a221e] shadow-md scale-[1.04]'
                                        : 'border-paper-rule dark:border-[#2b3530] text-ink-500 dark:text-[#6b7570] hover:border-forest-300 dark:hover:border-forest-600 hover:bg-forest-50 dark:hover:bg-forest-900/20 hover:text-forest-700 dark:hover:text-forest-400'"
                                    class="text-center py-2 rounded-xl border-2 text-[13px] font-semibold transition-colors">
                                {{ $val }}
                            </button>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>

                @if ($step === 8)
                <div class="mt-4 p-3 bg-paper-2 dark:bg-[#202a26] rounded-xl border border-paper-rule dark:border-[#2b3530] text-[11.5px] text-ink-500 dark:text-[#6b7570] text-center">
                    Section H is optional. You may skip if the senior prefers not to answer.
                </div>
                @endif
            </div>

            {{-- ── Navigation Footer ── --}}
            <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-4 flex items-center gap-3">
                <a href="{{ route('seniors.show', $senior) }}" wire:navigate class="btn btn-ghost text-[13px]">
                    Cancel
                </a>

                @if ($step > 1)
                <button type="button" wire:click="prevStep" class="btn">
                    ← Previous
                </button>
                @endif

                <button type="button" wire:click="saveDraft"
                        wire:loading.attr="disabled" wire:target="saveDraft"
                        class="btn btn-ghost text-[13px]">
                    <span wire:loading.remove wire:target="saveDraft">Save Draft</span>
                    <span wire:loading wire:target="saveDraft">Saving…</span>
                </button>

                <div class="ml-auto flex gap-3">
                    @if ($step < $totalSteps)
                    <button type="button" wire:click="nextStep"
                            wire:loading.attr="disabled" wire:target="nextStep"
                            class="btn btn-primary">
                        <span wire:loading.remove wire:target="nextStep" class="inline-flex items-center gap-1.5">
                            Next Section
                            <x-heroicon-o-arrow-right class="w-3.5 h-3.5" />
                        </span>
                        <span wire:loading.inline-flex wire:target="nextStep" class="items-center gap-1.5">
                            <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Loading…
                        </span>
                    </button>
                    @else
                    {{-- x-data here (not wire:loading alone) for the same reason the
                         confirm modal below uses it: an instant, synchronous
                         client-side lock on click, reset via the $wire promise once
                         Livewire's response actually lands — whether confirmSubmit()
                         opened the modal or threw a validation error. wire:loading
                         alone reports "request in flight" but field-testing found it
                         didn't visibly disable/spin for this specific button. --}}
                    <div x-data="{ submitting: false }">
                        <button type="button"
                                @click="submitting = true; $wire.confirmSubmit().then(() => submitting = false)"
                                x-bind:disabled="submitting"
                                class="btn btn-primary">
                            <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-check class="w-3.5 h-3.5" />
                                Submit &amp; Run Assessment
                            </span>
                            <span x-show="submitting" x-cloak class="inline-flex items-center gap-1.5">
                                <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Processing…
                            </span>
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>
        </form>
        </div>

        {{-- ── Right Rail: Survey Guide ── --}}
        <x-survey-guide
            title="Survey Guide"
            :intro="'Answer every question in Section ' . $step . ' to continue.'"
            :percent="$this->completionPercent()"
            :stepCaption="'Section ' . $step . ' of ' . $totalSteps"
            :statusText="$this->stepStatusText()"
            :tips="[
                'Answer honestly — there are no wrong answers.',
                'Each item is rated 1 (Strongly Disagree) to 5 (Strongly Agree).',
                'The final section is optional — you may skip it.',
            ]"
        />
    </div>

    {{-- ── Confirm Modal ── --}}
    @if ($showConfirm)
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="card max-w-md w-full shadow-2xl">
            <div class="card-head">
                <div class="card-title">Submit QoL Survey?</div>
            </div>
            <div class="card-body space-y-4">
                <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc]">
                    This will submit the Quality of Life survey for <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</strong>
                    and automatically run the health assessment.
                </p>
                {{-- x-data lives here so both buttons share `submitting`. It's set the
                     instant the submit button is pressed. wire:loading only reflects
                     "request in flight" — it turns off the moment the response arrives,
                     which is BEFORE the redirect's window.location assignment actually
                     unloads the page, so relying on wire:loading alone let this button
                     flip back to enabled during that gap. `submitting` never resets, so
                     both buttons stay locked through the whole gap until the browser
                     navigates away. --}}
                <div class="flex gap-3 justify-end" x-data="{ submitting: false }">
                    <button wire:click="$set('showConfirm', false)" class="btn" x-bind:disabled="submitting">Cancel</button>
                    <button wire:click="submitSurvey"
                            @click="submitting = true"
                            x-bind:disabled="submitting"
                            class="btn btn-primary">
                        <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-check class="w-3.5 h-3.5" />
                            Confirm &amp; Submit
                        </span>
                        <span x-show="submitting" x-cloak class="inline-flex items-center gap-1.5">
                            <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Processing…
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Loading overlay --}}
    @if ($isProcessing)
    <div class="fixed inset-0 bg-white/80 dark:bg-[#131917]/85 backdrop-blur-sm flex flex-col items-center justify-center z-50">
        <div class="animate-spin w-10 h-10 border-4 border-forest-500 border-t-transparent rounded-full mb-3"></div>
        <p class="text-[13px] font-medium text-ink-700 dark:text-[#c8c4bc]">Running Health Assessment…</p>
        <p class="text-[11.5px] text-ink-400 dark:text-[#6b7570] mt-1">Preparing data → Assigning profile group → Scoring risk → Generating recommendations</p>
    </div>
    @endif

</div>
