{{-- resources/views/livewire/surveys/profile-survey.blade.php --}}
<div class="max-w-3xl mx-auto" x-data="{}">

    {{-- ── Step Progress ── --}}
    <div class="mb-5">
        @php
        $stepLabels = [
            1 => 'I. Identifying Info',
            2 => 'II. Family',
            3 => 'III. Education / HR',
            4 => 'IV. Dependency',
            5 => 'V. Economic',
            6 => 'VI. Health',
        ];
        @endphp
        <div class="flex gap-1 mb-2">
            @foreach ($stepLabels as $s => $lbl)
            <button type="button" wire:click="goToStep({{ $s }})"
                    class="flex-1 py-2 text-xs font-medium rounded-lg transition-all
                           {{ $step === $s
                              ? 'bg-forest-700 text-white shadow-sm'
                              : ($step > $s
                                  ? 'bg-forest-100 dark:bg-forest-900/40 text-forest-700 dark:text-forest-400'
                                  : 'bg-paper-2 dark:bg-[#202a26] text-ink-500 dark:text-[#6b7570] hover:bg-paper-rule dark:hover:bg-[#2b3530]') }}">
                {{ $s }}. {{ explode('. ', $lbl)[1] ?? $lbl }}
            </button>
            @endforeach
        </div>
        <div class="w-full bg-paper-rule dark:bg-[#2b3530] rounded-full h-1">
            <div class="bg-forest-500 h-1 rounded-full transition-all duration-500"
                 style="width: {{ (($step - 1) / ($totalSteps - 1)) * 100 }}%"></div>
        </div>
    </div>

    {{-- Success banner --}}
    @if ($saved)
    <x-alert type="success" title="Profile saved" class="mb-4">
        OSCA ID: <strong>{{ $senior?->osca_id }}</strong>
        <a href="{{ $senior ? route('surveys.qol.create', $senior->id) : '#' }}"
           class="btn btn-primary ml-4 text-sm">
            + Take QoL Survey →
        </a>
    </x-alert>
    @endif

    {{-- ── Form Card ── --}}
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
            <h3 class="font-display text-xl text-ink-800 mb-5">I. Identifying Information</h3>
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-1">First Name <span class="text-critical-700" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="firstName" placeholder="Juan"
                           class="form-input">
                    @error('firstName') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-1">Middle Name</label>
                    <input type="text" wire:model="middleName" placeholder="Santos"
                           class="form-input">
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-1">Last Name <span class="text-critical-700" aria-hidden="true">*</span></label>
                    <input type="text" wire:model="lastName" placeholder="Dela Cruz"
                           class="form-input">
                    @error('lastName') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-1">Name Extension</label>
                    <input type="text" wire:model="nameExtension" placeholder="Jr., Sr., II"
                           class="form-input">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-1">Barangay <span class="text-critical-700" aria-hidden="true">*</span></label>
                    <select wire:model="barangay"
                            data-location-barangay
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
                    <input type="date" wire:model="dateOfBirth" max="{{ date('Y-m-d', strtotime('-60 years')) }}"
                           class="form-input {{ $errors->has('dateOfBirth') ? 'border-critical-400 focus:border-critical-500 focus:ring-critical-500/20' : '' }}">
                    @error('dateOfBirth') <p class="text-[11.5px] text-critical-700 dark:text-[#e08070] mt-1 flex items-center gap-1">{{ $message }}</p> @enderror
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
                        <input type="date" wire:model="consentGivenAt"
                               class="form-input">
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
            <h3 class="font-display text-xl text-ink-800 mb-5">II. Family Composition</h3>
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
            <h3 class="font-display text-xl text-ink-800 mb-5">III. Education / HR Profile</h3>
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
            <h3 class="font-display text-xl text-ink-800 mb-5">IV. Dependency Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-2">Living / Residing with (check all applicable)</label>
                    <div class="grid grid-cols-3 gap-2">
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
            <h3 class="font-display text-xl text-ink-800 mb-5">V. Economic Profile</h3>
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
                        <div class="space-y-1">
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
                        <div class="space-y-1">
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
                    <div class="grid grid-cols-2 gap-2">
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
            <h3 class="font-display text-xl text-ink-800 mb-5">VI. Health Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-ink-600 mb-2">Medical Concerns (check all applicable)</label>
                    <div class="grid grid-cols-3 gap-2">
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
                    <div class="grid grid-cols-2 gap-2">
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
                        <div class="space-y-1">
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
                        <div class="space-y-1">
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
                        <div class="space-y-1">
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
                        <div class="space-y-1">
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
            @if ($step > 1)
            <button type="button" wire:click="prevStep"
                    wire:loading.attr="disabled"
                    wire:target="prevStep"
                    class="btn">
                <x-heroicon-o-arrow-left class="w-3.5 h-3.5" />
                Back
            </button>
            @endif

            <div class="ml-auto flex gap-3">
                @if ($step < $totalSteps)
                <button type="button" wire:click="nextStep"
                        wire:loading.attr="disabled"
                        wire:target="nextStep"
                        class="btn btn-primary">
                    <span wire:loading.remove wire:target="nextStep" class="inline-flex items-center gap-1.5">
                        Next
                        <x-heroicon-o-arrow-right class="w-3.5 h-3.5" />
                    </span>
                    <span wire:loading wire:target="nextStep" class="inline-flex items-center gap-1.5">
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
                        class="btn btn-primary">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-check class="w-4 h-4" />
                        Save Profile
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-1.5">
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

@once
@push('scripts')
<script>
(function () {
    const PAGSANJAN_CENTER = [14.2708, 121.4560];
    const BARANGAY_CENTROIDS = {
        'Anibong': [14.2782, 121.4588],
        'BiÃ±an': [14.2757, 121.4506],
        'Buboy': [14.2667, 121.4602],
        'Calusiche': [14.2629, 121.4524],
        'Cabanbanan': [14.2685, 121.4477],
        'Dingin': [14.2738, 121.4621],
        'Lambac': [14.2688, 121.4591],
        'Layugan': [14.2712, 121.4495],
        'Magdapio': [14.2748, 121.4562],
        'Maulawin': [14.2737, 121.4625],
        'Pinagsanjan': [14.2657, 121.4512],
        'Barangay I (Poblacion)': [14.2719, 121.4551],
        'Barangay II (Poblacion)': [14.2704, 121.4567],
        'Sabang': [14.2752, 121.4529],
        'Sampaloc': [14.2674, 121.4632],
        'San Isidro': [14.2639, 121.4583],
    };

    function numberOrNull(value) {
        if (value === null || value === undefined || String(value).trim() === '') {
            return null;
        }

        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function hasValidCoordinatePair(lat, lng) {
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return false;

        return Math.abs(lat) >= 0.000001 && Math.abs(lng) >= 0.000001;
    }

    function selectedBarangayCenter(select) {
        return BARANGAY_CENTROIDS[select?.value] ?? PAGSANJAN_CENTER;
    }

    function setInputValue(input, value) {
        if (!input) return;
        input.value = Number(value).toFixed(7);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        setLivewireModel(input, input.value);
    }

    function setLivewireModel(input, value) {
        if (!input) return;

        const componentRoot = input.closest('[wire\\:id]');
        const componentId = componentRoot?.getAttribute('wire:id');
        const modelName = input.getAttribute('wire:model') || input.getAttribute('wire:model.live');
        const component = componentId && window.Livewire?.find ? window.Livewire.find(componentId) : null;
        if (component && modelName) {
            component.set(modelName, value);
        }
    }

    function setStatus(statusEl, message, tone = 'neutral') {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.className = 'text-[11px] font-medium text-right';
        if (tone === 'error') {
            statusEl.classList.add('text-red-600');
        } else if (tone === 'success') {
            statusEl.classList.add('text-emerald-700');
        } else {
            statusEl.classList.add('text-slate-500');
        }
    }

    function pointInRing(point, ring) {
        let inside = false;

        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const xi = Number(ring[i][0]);
            const yi = Number(ring[i][1]);
            const xj = Number(ring[j][0]);
            const yj = Number(ring[j][1]);

            if (![xi, yi, xj, yj].every(Number.isFinite)) continue;

            const intersects = ((yi > point[1]) !== (yj > point[1])) &&
                (point[0] < ((xj - xi) * (point[1] - yi)) / ((yj - yi) || Number.EPSILON) + xi);

            if (intersects) inside = !inside;
        }

        return inside;
    }

    function pointInPolygon(point, polygonCoordinates) {
        if (!Array.isArray(polygonCoordinates) || !polygonCoordinates.length) return false;
        if (!pointInRing(point, polygonCoordinates[0])) return false;

        return !polygonCoordinates.slice(1).some((hole) => pointInRing(point, hole));
    }

    function pointInsideBoundary(latlng, boundaryGeoJson) {
        if (!Array.isArray(boundaryGeoJson?.features) || boundaryGeoJson.features.length === 0) {
            return true;
        }

        const point = [latlng.lng, latlng.lat];

        return boundaryGeoJson.features.some((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;

            if (geometry?.type === 'Polygon') {
                return pointInPolygon(point, coordinates);
            }

            if (geometry?.type === 'MultiPolygon') {
                return Array.isArray(coordinates) &&
                    coordinates.some((polygon) => pointInPolygon(point, polygon));
            }

            return false;
        });
    }

    function updateValidity(latInput, lngInput, isInside) {
        const message = isInside ? '' : 'Selected location must be inside the Pagsanjan municipal boundary.';
        latInput?.setCustomValidity(message);
        lngInput?.setCustomValidity(message);
    }

    function initializeLocationPicker(el) {
        if (el._locationPickerInitialized || !window.L) {
            if (el._locationPickerMap) {
                setTimeout(() => el._locationPickerMap.invalidateSize(), 50);
            }
            return;
        }
        el._locationPickerInitialized = true;

        const form = el.closest('form') ?? document;
        const statusEl = form.querySelector('[data-location-status]');
        const barangaySelect = form.querySelector('[data-location-barangay]');
        const latInput = document.getElementById(el.dataset.latitudeInput);
        const lngInput = document.getElementById(el.dataset.longitudeInput);
        const touchedInput = document.getElementById(el.dataset.touchedInput);
        let boundaryGeoJson = null;
        let boundaryLayer = null;
        let marker = null;
        let lastValidLatLng = null;

        const verifiedPinIcon = window.L.divIcon({
            className: 'senior-location-pin',
            html: '<span style="display:block;width:20px;height:20px;border-radius:9999px;background:#0f766e;border:3px solid #fff;box-shadow:0 2px 10px rgba(15,23,42,.35);"></span>',
            iconSize: [20, 20],
            iconAnchor: [10, 10],
        });

        const initialLat = numberOrNull(latInput?.value || el.dataset.initialLatitude);
        const initialLng = numberOrNull(lngInput?.value || el.dataset.initialLongitude);
        const hasInitialPin = hasValidCoordinatePair(initialLat, initialLng);

        const map = window.L.map(el, {
            minZoom: 13,
            maxZoom: 19,
            zoomControl: true,
            preferCanvas: true,
        }).setView(PAGSANJAN_CENTER, 14);
        el._locationPickerMap = map;

        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        function ensureMarker(latlng) {
            if (!marker) {
                marker = window.L.marker(latlng, {
                    draggable: true,
                    icon: verifiedPinIcon,
                    keyboard: true,
                    title: 'Verified senior location',
                }).addTo(map);
                marker.on('dragend', () => applyPin(marker.getLatLng(), true));
            } else {
                marker.setLatLng(latlng);
            }
        }

        function markPinTouched() {
            if (!touchedInput) return;
            touchedInput.value = '1';
            touchedInput.dispatchEvent(new Event('input', { bubbles: true }));
            setLivewireModel(touchedInput, true);
        }

        function applyPin(latlng, fromMarker = false, markTouched = true) {
            const isInside = pointInsideBoundary(latlng, boundaryGeoJson);

            if (!isInside) {
                updateValidity(latInput, lngInput, false);
                setStatus(statusEl, 'Pin is outside Pagsanjan. Move it inside before saving.', 'error');

                if (fromMarker && marker && lastValidLatLng) {
                    marker.setLatLng(lastValidLatLng);
                }

                return false;
            }

            ensureMarker(latlng);
            lastValidLatLng = window.L.latLng(latlng.lat, latlng.lng);

            setInputValue(latInput, latlng.lat);
            setInputValue(lngInput, latlng.lng);
            if (markTouched) {
                markPinTouched();
            }

            updateValidity(latInput, lngInput, true);
            setStatus(statusEl, 'Verified pin inside Pagsanjan boundary.', 'success');

            return true;
        }

        if (hasInitialPin) {
            applyPin(window.L.latLng(initialLat, initialLng), false, false);
            map.setView([initialLat, initialLng], 17, { animate: false });
        } else {
            latInput.value = '';
            lngInput.value = '';
            if (touchedInput) {
                touchedInput.value = '';
                setLivewireModel(touchedInput, false);
            }
            updateValidity(latInput, lngInput, true);
            setStatus(statusEl, 'Click inside Pagsanjan to set the verified location.');
        }

        map.on('click', (event) => {
            if (applyPin(event.latlng)) {
                map.panTo(event.latlng, { animate: true });
            }
        });

        [latInput, lngInput].forEach((input) => {
            input?.addEventListener('change', () => {
                const lat = numberOrNull(latInput?.value);
                const lng = numberOrNull(lngInput?.value);
                if (hasValidCoordinatePair(lat, lng)) {
                    applyPin(window.L.latLng(lat, lng));
                    map.setView([lat, lng], Math.max(map.getZoom(), 16));
                }
            });
        });

        barangaySelect?.addEventListener('change', () => {
            if (!marker && !boundaryLayer?.getBounds().isValid()) {
                map.setView(selectedBarangayCenter(barangaySelect), 15, { animate: true });
            }
        });

        fetch(el.dataset.boundaryUrl, { headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : null)
            .then((geoJson) => {
                if (!geoJson || !Array.isArray(geoJson.features)) return;
                boundaryGeoJson = geoJson;
                boundaryLayer = window.L.geoJSON(geoJson, {
                    style: {
                        color: '#0f766e',
                        weight: 2,
                        opacity: 0.9,
                        fillOpacity: 0.04,
                    },
                }).addTo(map);

                if (boundaryLayer.getBounds().isValid()) {
                    const boundaryBounds = boundaryLayer.getBounds();
                    map.setMaxBounds(boundaryBounds.pad(0.3));

                    if (marker) {
                        const markerBounds = window.L.latLngBounds([marker.getLatLng()]).extend(boundaryBounds);
                        map.fitBounds(markerBounds.pad(0.05), { maxZoom: 17, animate: false });
                    } else {
                        map.fitBounds(boundaryBounds.pad(0.05), { maxZoom: 15, animate: false });
                    }
                }

                if (marker) {
                    applyPin(marker.getLatLng(), true, false);
                }

                setTimeout(() => map.invalidateSize(), 50);
            })
            .catch(() => setStatus(statusEl, 'Boundary could not load. Server validation still runs on save.', 'neutral'));

        requestAnimationFrame(() => map.invalidateSize());
        setTimeout(() => map.invalidateSize(), 150);
        setTimeout(() => map.invalidateSize(), 450);
    }

    function initializeLocationPickers() {
        document.querySelectorAll('[data-location-picker]').forEach(initializeLocationPicker);
    }

    document.addEventListener('DOMContentLoaded', initializeLocationPickers);
    document.addEventListener('livewire:navigated', () => setTimeout(initializeLocationPickers, 0));
    document.addEventListener('livewire:updated', () => setTimeout(initializeLocationPickers, 0));
    new MutationObserver(() => initializeLocationPickers()).observe(document.body, { childList: true, subtree: true });
})();
</script>
@endpush
@endonce
