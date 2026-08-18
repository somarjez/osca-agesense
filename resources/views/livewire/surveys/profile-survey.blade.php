{{-- resources/views/livewire/surveys/profile-survey.blade.php --}}
@php
    $xt = \App\Livewire\Surveys\ProfileSurvey::EXCLUSIVE_TOKENS;
    $stepLabels = [
        1 => ['label' => 'I. Identifying Info', 'sub' => 'Basic information'],
        2 => ['label' => 'II. Family', 'sub' => 'Family details'],
        3 => ['label' => 'III. Education / HR', 'sub' => 'Education & work'],
        4 => ['label' => 'IV. Dependency', 'sub' => 'Dependent info'],
        5 => ['label' => 'V. Economic', 'sub' => 'Income & assets'],
        6 => ['label' => 'VI. Health', 'sub' => 'Health information'],
    ];
    // Built as a plain variable (not inlined into the component tag) because the
    // third tip's literal double quotes around "Unassigned" would otherwise sit
    // inside the tag's own double-quoted :tips="..." attribute — Blade's tag
    // compiler has no escape convention for that and silently fails to compile
    // the whole component tag (it falls through as literal, unrendered text).
    $registrationTips = [
        'Ensure the name matches the government ID.',
        'Use the official birth date format (DD/MM/YYYY).',
        'OSCA ID will be generated as "Unassigned"',
    ];
@endphp
<div class="max-w-6xl mx-auto" x-data="nameGuard(@js($this->nameGuardConfig()))">

    {{-- ── Header ── --}}
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-serif text-2xl font-semibold text-ink-900 dark:text-[#e4e1d8] tracking-snug">
                {{ $senior ? 'Edit Senior Citizen Profile' : 'Register New Senior Citizen' }}
            </h1>
            <p class="text-[13px] text-ink-500 dark:text-[#8a9087] mt-0.5">Complete the OSCA profile survey form.</p>
        </div>
        <span class="badge badge-neutral flex-shrink-0 mt-1 tnum">Step {{ $step }} of {{ $totalSteps }}</span>
    </div>

    <x-survey-stepper :steps="$stepLabels" :current="$step" :total="$totalSteps" click="goToStep" />

    {{-- Success modal — forces an explicit next action --}}
    @if ($saved && $senior)
    <div x-data="{ open: true }">
        <x-modal show="open" max-width="max-w-md" :closeable="false" aria-label="Profile saved confirmation">
            <div class="text-center">
                <div class="w-12 h-12 rounded-2xl grid place-items-center mx-auto mb-3
                            bg-forest-50 dark:bg-forest-900/40 text-forest-700 dark:text-forest-300">
                    <x-heroicon-o-check-circle class="w-7 h-7" aria-hidden="true" />
                </div>
                <h2 class="font-display text-xl text-ink-900 dark:text-[#e4e1d8] mb-1">Profile Saved</h2>
                <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] mb-1">OSCA ID</p>
                <p class="font-mono text-lg mb-2 {{ $senior->official_osca_id ? 'font-bold tracking-wide text-forest-700 dark:text-forest-300' : 'italic font-normal text-ink-400 dark:text-[#6b7570]' }}">
                    {{ $senior->official_osca_id_display }}
                </p>
                <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] mb-1">System ID</p>
                <p class="font-mono text-sm font-medium tracking-wide text-ink-500 dark:text-[#8a9087] mb-5">
                    {{ $senior->osca_id }}
                </p>
                <div class="flex flex-col gap-2">
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-primary justify-center">
                        + Take QoL Survey →
                    </a>
                    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-secondary justify-center">
                        ← Back to Profile
                    </a>
                </div>
            </div>
        </x-modal>
    </div>
    @endif

    {{-- Save-time validation is also surfaced in a fixed modal so users do not
         have to find the inline summary in a long, multi-step form. --}}
    <div x-data="{ open: false, title: 'Unable to save record', messages: [] }"
         @profile-validation-failed.window="
            title = $event.detail.title || 'Unable to save record';
            messages = Array.isArray($event.detail.messages) ? $event.detail.messages : [];
            open = true;
         ">
        <x-modal show="open" max-width="max-w-md" ariaLabel="Save validation errors">
            <div role="alert" aria-live="assertive">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl grid place-items-center flex-shrink-0 bg-critical-50 dark:bg-critical-900/30 text-critical-700 dark:text-critical-300">
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="card-title" x-text="title"></h2>
                        <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] mt-1">
                            Please correct the following information and try again.
                        </p>
                    </div>
                </div>
                <ul class="mt-4 space-y-2 text-[13px] text-critical-700 dark:text-[#e08070] text-left">
                    <template x-for="(message, index) in messages" :key="index">
                        <li class="flex items-start gap-2">
                            <span aria-hidden="true">&bull;</span>
                            <span x-text="message"></span>
                        </li>
                    </template>
                </ul>
                <div class="flex justify-end mt-5">
                    <button type="button" class="btn btn-primary" @click="open = false">Review form</button>
                </div>
            </div>
        </x-modal>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">

        {{-- ── Form Card ── --}}
        <div>
        <form wire:submit.prevent="save">
        <div class="card">

            {{-- Validation errors --}}
            @if ($errors->any())
            <div class="px-5 pt-4 pb-0">
                <div class="flex items-start gap-3 bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30 rounded-xl px-4 py-3 mb-4">
                    <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-critical-700 dark:text-[#e08070] flex-shrink-0 mt-0.5" />
                    <div>
                        <p class="text-[12.5px] font-semibold text-critical-700 dark:text-[#e08070] mb-1">Please fix the following errors:</p>
                        <ul class="text-[12px] text-critical-700 dark:text-[#e08070] space-y-0.5">
                            @foreach ($errors->all() as $err)
                                <li>• {{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            @endif

            <div class="p-5">

                {{-- ─── STEP 1: Identifying Information ─── --}}
                @if ($step === 1)
                <x-survey-section-head :step="1" title="Identifying Information"
                    desc="Provide the senior citizen's basic identifying and demographic details." />

                {{-- Record Status — edit-only. New profiles are always created Active.
                     recordStatus is a client-side Alpine mirror of $status so the
                     Deceased fields reveal instantly on click instead of waiting on
                     a Livewire round-trip; wire:model (deferred) still carries the
                     real value to the server on the next save/step action. --}}
                @if ($senior)
                <div class="mb-5 p-4 rounded-xl border transition-colors"
                     x-data="{ recordStatus: @js($status) }"
                     :class="recordStatus === 'deceased' ? 'border-critical-200 dark:border-critical-700/30 bg-critical-50 dark:bg-critical-50/10' : 'border-paper-rule bg-paper'">
                    <p class="text-xs font-semibold text-ink-500 uppercase tracking-wider mb-3">Record Status</p>
                    <div class="flex gap-3">
                        @foreach (['active' => 'Active', 'deceased' => 'Deceased'] as $val => $label)
                        <label class="flex-1 flex items-center gap-2 cursor-pointer px-3 py-2.5 rounded-lg border transition-colors"
                               :class="recordStatus === '{{ $val }}'
                                       ? '{{ $val === 'deceased' ? 'border-critical-500 bg-critical-100 dark:bg-critical-700/20' : 'border-forest-500 bg-forest-50 dark:bg-forest-900/30' }}'
                                       : 'border-paper-rule hover:bg-paper dark:hover:bg-[#202a26]'">
                            <input type="radio" wire:model="status" x-model="recordStatus" value="{{ $val }}" class="accent-forest-700">
                            <span class="text-sm font-medium text-ink-700">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('status') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1">{{ $message }}</p> @enderror

                    <div x-show="recordStatus === 'deceased'" x-cloak class="grid grid-cols-2 gap-4 mt-3 pt-3 border-t border-paper-2">
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-1">Date of Death</label>
                            <input type="date" wire:model="dateOfDeath" min="1900-01-01" max="{{ date('Y-m-d') }}"
                                   class="form-input {{ $errors->has('dateOfDeath') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                            @error('dateOfDeath') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-ink-600 mb-1">Note (optional)</label>
                            <textarea wire:model="deceasedNote" rows="2" maxlength="500" placeholder="Cause, context, or other notes…"
                                      class="form-input"></textarea>
                            @error('deceasedNote') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($senior->status_changed_at)
                    <p class="text-[11px] text-ink-400 dark:text-[#6b7570] mt-2">
                        Status last changed by {{ $senior->status_changed_by ?? 'unknown' }} on {{ $senior->status_changed_at->format('M j, Y g:ia') }}
                    </p>
                    @endif
                </div>
                @endif

                <div class="grid grid-cols-2 gap-4">

                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">First Name <span class="text-critical-700" aria-hidden="true">*</span></label>
                        <input type="text" wire:model="firstName" placeholder="Juan"
                               @input="check('firstName', $event.target.value)"
                               :aria-invalid="!!errors.firstName"
                               class="form-input">
                        <p x-show="errors.firstName" x-cloak x-text="errors.firstName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1"></p>
                        @error('firstName') <p x-show="!errors.firstName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Middle Name</label>
                        <input type="text" wire:model="middleName" placeholder="Santos"
                               @input="check('middleName', $event.target.value)"
                               :aria-invalid="!!errors.middleName"
                               class="form-input">
                        <p x-show="errors.middleName" x-cloak x-text="errors.middleName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1"></p>
                        @error('middleName') <p x-show="!errors.middleName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Last Name <span class="text-critical-700" aria-hidden="true">*</span></label>
                        <input type="text" wire:model="lastName" placeholder="Dela Cruz"
                               @input="check('lastName', $event.target.value)"
                               :aria-invalid="!!errors.lastName"
                               class="form-input">
                        <p x-show="errors.lastName" x-cloak x-text="errors.lastName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1"></p>
                        @error('lastName') <p x-show="!errors.lastName" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Name Extension</label>
                        <input type="text" wire:model="nameExtension" placeholder="Jr., Sr., II"
                               @input="check('nameExtension', $event.target.value)"
                               :aria-invalid="!!errors.nameExtension"
                               class="form-input">
                        <p x-show="errors.nameExtension" x-cloak x-text="errors.nameExtension" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1"></p>
                        @error('nameExtension') <p x-show="!errors.nameExtension" class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Barangay <span class="text-critical-700" aria-hidden="true">*</span></label>
                        <select wire:model="barangay"
                                class="form-select {{ $errors->has('barangay') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                            <option value="">Select barangay…</option>
                            @foreach (\App\Models\SeniorCitizen::barangayList() as $b)
                                <option value="{{ $b }}">{{ $b }}</option>
                            @endforeach
                        </select>
                        @error('barangay') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Date of Birth <span class="text-critical-700" aria-hidden="true">*</span></label>
                        <input type="date" wire:model.blur="dateOfBirth" min="1900-01-01" max="{{ date('Y-m-d', strtotime('-60 years')) }}"
                               class="form-input {{ $errors->has('dateOfBirth') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                        @error('dateOfBirth') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Registration Date</label>
                        <input type="date" wire:model.blur="registrationDate" min="1900-01-01" max="{{ date('Y-m-d') }}"
                               class="form-input {{ $errors->has('registrationDate') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                        <p class="text-[11px] text-ink-400 dark:text-[#6b7570] mt-1">When the senior was registered with OSCA.</p>
                        @error('registrationDate') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">OSCA ID</label>
                        <input type="text" wire:model="officialOscaId" placeholder="e.g. 12-3456-789"
                               class="form-input {{ $errors->has('officialOscaId') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                        <p class="text-[11px] text-ink-400 dark:text-[#6b7570] mt-1">Leave blank if not yet assigned.</p>
                        @error('officialOscaId') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Gender</label>
                        <select wire:model="gender"
                                class="form-input">
                            <option value="">Select…</option>
                            <option>Male</option><option>Female</option><option>Prefer not to say</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Marital Status</label>
                        <select wire:model="maritalStatus"
                                class="form-input">
                            <option value="">Select…</option>
                            @foreach (['Single','Married','Widowed','Separated'] as $ms)
                                <option>{{ $ms }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Religion</label>
                        <select wire:model="religion"
                                class="form-input">
                            <option value="">Select…</option>
                            @foreach (['Roman Catholic','Iglesia ni Cristo','Islam','Protestant / Evangelical','Seventh-day Adventist','Jehovah\'s Witness','Aglipayan','Other'] as $rel)
                                <option>{{ $rel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Blood Type</label>
                        <select wire:model="bloodType"
                                class="form-input">
                            <option value="">Unknown</option>
                            @foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bt)
                                <option>{{ $bt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Contact Number</label>
                        <input type="text" wire:model="contactNumber" placeholder="09XX XXX XXXX"
                               class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Place of Birth</label>
                        <input type="text" wire:model="placeOfBirth"
                               class="form-input">
                    </div>
                </div>


                {{-- Data Privacy Consent --}}
                <div class="mt-5 pt-4 border-t border-paper-2">
                    <p class="text-xs font-semibold text-ink-500 uppercase tracking-wider mb-3">Data Privacy Consent (RA 10173)</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-1">Consent Date</label>
                            <input type="date" wire:model.blur="consentGivenAt" min="1900-01-01" max="{{ date('Y-m-d') }}"
                                   class="form-input {{ $errors->has('consentGivenAt') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                            @error('consentGivenAt') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-1">Consent Method</label>
                            <select wire:model="consentMethod"
                                    class="form-input">
                                <option value="">Not recorded</option>
                                <option value="verbal">Verbal</option>
                                <option value="written">Written</option>
                                <option value="digital">Digital</option>
                            </select>
                        </div>
                    </div>
                </div>
                @endif

                {{-- ─── STEP 2: Family Composition ─── --}}
                @if ($step === 2)
                <x-survey-section-head :step="2" title="Family Composition"
                    desc="Household size and family support details." />
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Number of Children</label>
                        <input type="number" wire:model="numChildren" min="0"
                               class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Number of Working Children</label>
                        <input type="number" wire:model="numWorkingChildren" min="0"
                               class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Household Size (total members)</label>
                        <input type="number" wire:model="householdSize" min="1"
                               class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-1">Financially Supported by Children?</label>
                        <select wire:model="childFinancialSupport"
                                class="form-input">
                            <option value="">Select…</option>
                            <option>Yes</option><option>No</option><option>Occasional</option><option>N/A</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-ink-600 mb-1">Spouse / Partner Working?</label>
                        <div class="flex gap-3">
                            @foreach (['Yes','No','Deceased','N/A'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="spouseWorking" value="{{ $opt }}" class="accent-forest-700">
                                <span class="text-sm text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                {{-- ─── STEP 3: Education / HR ─── --}}
                @if ($step === 3)
                <x-survey-section-head :step="3" title="Education / HR Profile"
                    desc="Educational background, skills, and community involvement." />
                <div class="space-y-5">
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Educational Attainment</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (['Not Attended School','Elementary Level','Elementary Graduate','High School Level','High School Graduate','Vocational','College Level','College Graduate','Post Graduate'] as $edu)
                            <label class="flex items-center gap-2 cursor-pointer p-2 rounded-lg border border-paper-rule hover:bg-paper transition-colors {{ $educationalAttainment === $edu ? 'border-forest-500 bg-forest-50' : '' }}">
                                <input type="radio" wire:model="educationalAttainment" value="{{ $edu }}" class="accent-forest-700">
                                <span class="text-xs text-ink-700">{{ $edu }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Areas of Specialization / Technical Skills (check all applicable)</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ($this->specializationOptions() as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="specialization" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Community Service and Involvement (check all applicable)</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($this->communityServiceOptions() as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="communityService" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                {{-- ─── STEP 4: Dependency Profile ─── --}}
                @if ($step === 4)
                <x-survey-section-head :step="4" title="Dependency Profile"
                    desc="Living arrangement and household condition." />
                <div class="space-y-5">
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Living / Residing with (check all applicable)</label>
                        <div class="grid grid-cols-3 gap-2"
                             x-data="exclusiveGroup('livingWith', @js($xt['livingWith']))" @change="onChange($event)">
                            @foreach (['Alone','Spouse','Children','Grandchildren','Relative(s)','Friend(s)','Care Institution'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer p-2 border border-paper-rule rounded-lg hover:bg-paper">
                                <input type="checkbox" wire:model="livingWith" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Household Condition (check all applicable)</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ([
                                'No privacy','Overcrowded in home','Informal settler','No permanent house',
                                'High cost of rent','Longing for independent living quiet atmosphere',
                                'House is owned','Land is not owned','Shared with relatives',
                                'Government-Provided',
                            ] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="householdCondition" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                {{-- ─── STEP 5: Economic Profile ─── --}}
                @if ($step === 5)
                <x-survey-section-head :step="5" title="Economic Profile"
                    desc="Income sources, assets, and common problems or needs." />
                <div class="space-y-5">
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Source of Income and Assistance</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ($this->incomeSourceOptions() as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="incomeSource" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Monthly Income Range</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (['Below 1,000','1,000 - 5,000','5,000 - 10,000','10,000 - 20,000','20,000 - 30,000','30,000 - 40,000','40,000 - 50,000','50,000 - 60,000','60,000 and above'] as $inc)
                            <label class="flex items-center gap-2 cursor-pointer p-2 border border-paper-rule rounded-lg hover:bg-paper {{ $monthlyIncomeRange===$inc ? 'border-forest-500 bg-forest-50' : '' }}">
                                <input type="radio" wire:model="monthlyIncomeRange" value="{{ $inc }}" class="accent-forest-700">
                                <span class="text-xs text-ink-700">₱{{ $inc }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Real / Immovable Assets</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('realAssets', @js($xt['realAssets']))" @change="onChange($event)">
                                @foreach (['House','Lot/Farmland','House and Lot','Commercial Building','Apartment/Rental Unit','Fishpond/Resort','Agricultural Land/Farm','No known assets'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="realAssets" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Personal / Movable Assets</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('movableAssets', @js($xt['movableAssets']))" @change="onChange($event)">
                                @foreach (['Automobile','Motorcycle','Bicycle','Personal Computer','Laptop','Tablet','Mobile Phone','Heavy Equipment','Appliances (Refrigerator / TV / Washing Machine)','No known assets'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="movableAssets" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Problems / Needs Commonly Encountered</label>
                        <div class="grid grid-cols-2 gap-2"
                             x-data="exclusiveGroup('problemsNeeds', @js($xt['problemsNeeds']))" @change="onChange($event)">
                            @foreach ([
                                'Lack of income/resources',
                                'Loss of income/resources',
                                'Skills/Capability Training',
                                'Livelihood opportunities',
                                'Health Related Issues',
                                'Lack of access to healthcare services',
                                'High cost of medicines',
                                'Lack of social support',
                                'Limited Mobility/Transportation difficulty',
                                'Housing/Shelter',
                                'Food insecurity',
                                'Limited problems encountered',
                                'Others',
                            ] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="problemsNeeds" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                        @if (in_array('Others', $problemsNeeds))
                        <div class="mt-2">
                            <input type="text" wire:model="problemsNeedsOther"
                                   placeholder="Please specify..."
                                   class="form-input">
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- ─── STEP 6: Health Profile ─── --}}
                @if ($step === 6)
                <x-survey-section-head :step="6" title="Health Profile"
                    desc="Medical, dental, sensory, and healthcare access concerns." />
                <div class="space-y-5">
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Medical Concerns (check all applicable)</label>
                        <div class="grid grid-cols-3 gap-2"
                             x-data="exclusiveGroup('medicalConcern', @js($xt['medicalConcern']))" @change="onChange($event)">
                            @foreach ($this->medicalConcernOptions() as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="medicalConcern" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-600 mb-2">Social / Emotional Concerns (check all applicable)</label>
                        <div class="grid grid-cols-2 gap-2"
                             x-data="exclusiveGroup('socialEmotionalConcern', @js($xt['socialEmotionalConcern']))" @change="onChange($event)">
                            @foreach ($this->socialEmotionalConcernOptions() as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="socialEmotionalConcern" value="{{ $opt }}" class="accent-forest-700 rounded">
                                <span class="text-xs text-ink-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Dental Concern (check all applicable)</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('dentalConcern', @js($xt['dentalConcern']))" @change="onChange($event)">
                                @foreach (['Needs dental care','Tooth decay/cavities','Gum disease','Tooth loss/missing teeth','Healthy Teeth'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="dentalConcern" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Optical / Vision (check all applicable)</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('opticalConcern', @js($xt['opticalConcern']))" @change="onChange($event)">
                                @foreach (['Eye impairment','Needs eye care','Blurred vision','Cataract','Glaucoma','Healthy Eyes'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="opticalConcern" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Hearing (check all applicable)</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('hearingConcern', @js($xt['hearingConcern']))" @change="onChange($event)">
                                @foreach (['Hearing impairment','Partial hearing loss','Difficulty hearing conversations','Uses hearing aid','Healthy Hearing'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="hearingConcern" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-600 mb-2">Healthcare Access Difficulty (check all applicable)</label>
                            <div class="space-y-1"
                                 x-data="exclusiveGroup('healthcareDifficulty', @js($xt['healthcareDifficulty']))" @change="onChange($event)">
                                @foreach (['High cost of medicines','Lack of medicines','Lack of medical attention','Difficulty accessing health facilities','Lack of transportation to clinics','Long waiting time','Healthcare is accessible'] as $opt)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model="healthcareDifficulty" value="{{ $opt }}" class="accent-forest-700 rounded">
                                    <span class="text-xs text-ink-700">{{ $opt }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-paper rounded-xl border border-paper-rule">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="hasMedicalCheckup" class="accent-forest-700 w-4 h-4 rounded">
                            <span class="text-sm font-medium text-ink-700">Has scheduled medical / physical check-up</span>
                        </label>
                        @if ($hasMedicalCheckup)
                        <div class="mt-3 ml-7">
                            <label class="block text-xs text-ink-500 mb-2">How often?</label>
                            <div class="flex flex-wrap gap-4">
                                @foreach (['Every Month', 'Every 3 months', 'Every 6 months', 'Others'] as $sch)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model="checkupSchedule" value="{{ $sch }}" class="accent-forest-700">
                                    <span class="text-sm text-ink-700">{{ $sch }}</span>
                                </label>
                                @endforeach
                            </div>
                            @if ($checkupSchedule === 'Others')
                            <div class="mt-2">
                                <input type="text" wire:model="checkupScheduleOther"
                                       placeholder="Please specify schedule..."
                                       class="form-input">
                            </div>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
                @endif

            </div>

            {{-- ── Footer Navigation ── --}}
            <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-4 flex items-center gap-3">
                <a href="{{ $senior ? route('seniors.show', $senior) : route('seniors.index') }}" wire:navigate
                   class="btn btn-ghost text-[13px]">
                    Cancel
                </a>

                @if ($step > 1)
                <button type="button" wire:click="prevStep"
                        wire:loading.attr="disabled"
                        wire:target="prevStep"
                        class="btn">
                    <x-heroicon-o-arrow-left class="w-3.5 h-3.5" />
                    Back
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
                            wire:loading.attr="disabled"
                            wire:target="nextStep"
                            :disabled="hasNameError"
                            class="btn btn-primary">
                        <span wire:loading.remove wire:target="nextStep" class="inline-flex items-center gap-1.5">
                            Next
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
                    <button wire:click="save"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            :disabled="hasNameError"
                            class="btn btn-primary">
                        <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-check class="w-4 h-4" />
                            Save Profile
                        </span>
                        <span wire:loading.inline-flex wire:target="save" class="items-center gap-1.5">
                            <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Saving…
                        </span>
                    </button>
                    @endif
                </div>
            </div>
        </div>
        </form>
        </div>

        {{-- ── Right Rail: Registration Guide ── --}}
        @php
            // Only Step 1 (Identifying Information) has any required fields —
            // every later step is optional by design. Naming "required
            // fields" on a step that has none was misleading (and is why the
            // ring below is now Progress through the wizard, not a
            // required-fields ratio — see completionPercent()'s docblock).
            $registrationIntro = $step === 1
                ? 'Fill out all required fields in Step 1 to proceed.'
                : 'This section is optional. Fill in what applies, or continue to the next step.';
        @endphp
        <x-survey-guide
            title="Registration Guide"
            :intro="$registrationIntro"
            :percent="$this->completionPercent()"
            label="Progress"
            :stepCaption="'Step ' . $step . ' of ' . $totalSteps"
            :statusText="$this->stepStatusText()"
            :tips="$registrationTips"
        />
    </div>
</div>
