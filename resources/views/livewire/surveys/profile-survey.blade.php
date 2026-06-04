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
                <p class="font-mono text-lg font-bold tracking-wide text-forest-700 dark:text-forest-300 mb-5">
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
