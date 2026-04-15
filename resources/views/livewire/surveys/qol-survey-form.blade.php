{{-- resources/views/livewire/surveys/qol-survey-form.blade.php --}}
<div class="max-w-3xl mx-auto" x-data="{ scale: [
    { value: 1, label: 'Strongly Disagree' },
    { value: 2, label: 'Disagree' },
    { value: 3, label: 'Neither' },
    { value: 4, label: 'Agree' },
    { value: 5, label: 'Strongly Agree' }
]}">

    {{-- ── Senior Banner ── --}}
    <div class="bg-white border border-slate-200 rounded-xl px-5 py-4 mb-5 shadow-sm flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0">
            <span class="font-bold text-teal-700">{{ substr($senior->first_name, 0, 1) }}</span>
        </div>
        <div>
            <p class="font-semibold text-slate-800">{{ $senior->full_name }}</p>
            <p class="text-sm text-slate-500">{{ $senior->barangay }} · Age {{ $senior->age }} · OSCA ID: {{ $senior->osca_id }}</p>
        </div>
        <div class="ml-auto">
            <label class="text-xs text-slate-500">Survey Date</label>
            <input type="date" wire:model="surveyDate"
                   class="block text-sm border border-slate-200 rounded-lg px-2 py-1 focus:ring-2 focus:ring-teal-500 focus:border-transparent">
        </div>
    </div>

    {{-- ── Step Progress ── --}}
    <div class="mb-5">
        <div class="flex items-center justify-between mb-2">
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
            @foreach ($sections as $s => $label)
            <button wire:click="goToStep({{ $s }})"
                    class="flex flex-col items-center gap-1 cursor-pointer group">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-all
                    {{ $step === $s ? 'bg-teal-600 text-white shadow-md' :
                       (count($progress[$s]) > 0 ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500 group-hover:bg-slate-300') }}">
                    @if (count($progress[$s]) > 0 && $step !== $s)
                        ✓
                    @else
                        {{ $s }}
                    @endif
                </div>
            </button>
            @endforeach
        </div>
        <div class="w-full bg-slate-200 rounded-full h-1.5">
            <div class="bg-teal-500 h-1.5 rounded-full transition-all duration-500"
                 style="width: {{ (($step - 1) / ($totalSteps - 1)) * 100 }}%"></div>
        </div>
        <p class="text-xs text-slate-500 mt-1 text-center">
            Section {{ $step }} of {{ $totalSteps }}: {{ $sections[$step] }}
        </p>
    </div>

    {{-- ── Question Card ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">

        {{-- Instructions header (step 1 only) --}}
        @if ($step === 1)
        <div class="bg-teal-50 border-b border-teal-100 px-5 py-3">
            <p class="text-sm text-teal-800">
                <strong>Instructions:</strong> In the past four weeks, how true is each statement for you?
                Rate each item from <strong>1 (Strongly Disagree)</strong> to <strong>5 (Strongly Agree)</strong>.
            </p>
        </div>
        @endif

        <div class="p-5">

            {{-- Section title --}}
            <h3 class="font-display text-xl text-slate-800 mb-5">
                {{ $sections[$step] }}
            </h3>

            {{-- Scale legend --}}
            <div class="grid grid-cols-5 gap-1 mb-6 text-center">
                @foreach ([1 => 'Strongly Disagree', 2 => 'Disagree', 3 => 'Neither', 4 => 'Agree', 5 => 'Strongly Agree'] as $v => $lbl)
                <div class="text-xs text-slate-500">
                    <div class="font-bold text-slate-700 text-sm">{{ $v }}</div>
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
                <div class="border border-slate-100 rounded-xl p-4 hover:border-teal-200 transition-colors"
                     x-data="{}">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="flex-shrink-0 w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-600">
                            {{ $q['item'] }}
                        </span>
                        <p class="text-sm text-slate-800 leading-relaxed">
                            {{ $q['text'] }}
                            @if ($q['reverse'])
                            <span class="ml-1 text-xs text-amber-600 font-medium">(↺ reverse)</span>
                            @endif
                        </p>
                    </div>
                    <div class="grid grid-cols-5 gap-2">
                        @foreach ([1,2,3,4,5] as $val)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model="{{ $prop }}" value="{{ $val }}" class="sr-only peer">
                            <div class="text-center py-2 rounded-lg border-2 text-sm font-semibold transition-all
                                border-slate-200 text-slate-500
                                peer-checked:border-teal-500 peer-checked:bg-teal-500 peer-checked:text-white
                                hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700">
                                {{ $val }}
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            @if ($step === 8)
            <div class="mt-4 p-3 bg-slate-50 rounded-lg border border-slate-200 text-xs text-slate-500 text-center">
                Section H is optional. You may skip if the senior prefers not to answer.
            </div>
            @endif
        </div>

        {{-- ── Navigation Footer ── --}}
        <div class="border-t border-slate-100 px-5 py-4 flex items-center gap-3">
            @if ($step > 1)
            <button wire:click="prevStep"
                    class="px-4 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                ← Previous
            </button>
            @endif

            <button wire:click="saveDraft"
                    class="px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors">
                Save Draft
            </button>

            <div class="ml-auto flex gap-3">
                @if ($step < $totalSteps)
                <button wire:click="nextStep"
                        class="px-5 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors shadow-sm">
                    Next Section →
                </button>
                @else
                <button wire:click="confirmSubmit"
                        class="px-5 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 transition-colors shadow-sm">
                    Submit & Run ML Analysis
                </button>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Confirm Modal ── --}}
    @if ($showConfirm)
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
            <h3 class="font-display text-xl text-slate-800 mb-2">Submit QoL Survey?</h3>
            <p class="text-sm text-slate-600 mb-4">
                This will submit the Quality of Life survey for <strong>{{ $senior->full_name }}</strong>
                and automatically trigger the ML analysis pipeline (preprocessing → clustering → risk scoring → recommendations).
            </p>
            <div class="flex gap-3 justify-end">
                <button wire:click="$set('showConfirm', false)"
                        class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">
                    Cancel
                </button>
                <button wire:click="submitSurvey"
                        class="px-5 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700">
                    <span wire:loading.remove wire:target="submitSurvey">✓ Confirm & Submit</span>
                    <span wire:loading wire:target="submitSurvey">Processing…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Loading overlay --}}
    @if ($isProcessing)
    <div class="fixed inset-0 bg-white/80 backdrop-blur-sm flex flex-col items-center justify-center z-50">
        <div class="animate-spin w-10 h-10 border-4 border-teal-500 border-t-transparent rounded-full mb-3"></div>
        <p class="text-sm font-medium text-slate-600">Running ML Analysis Pipeline…</p>
        <p class="text-xs text-slate-400 mt-1">Preprocessing → Clustering → Risk Scoring → Recommendations</p>
    </div>
    @endif

</div>
