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
            <button wire:click="goToStep({{ $s }})"
                    class="flex-1 py-2 text-xs font-medium rounded-lg transition-all
                           {{ $step === $s
                              ? 'bg-teal-600 text-white shadow-sm'
                              : ($step > $s ? 'bg-teal-100 text-teal-700' : 'bg-slate-100 text-slate-500 hover:bg-slate-200') }}">
                {{ $s }}. {{ explode('. ', $lbl)[1] ?? $lbl }}
            </button>
            @endforeach
        </div>
        <div class="w-full bg-slate-200 rounded-full h-1">
            <div class="bg-teal-500 h-1 rounded-full transition-all duration-500"
                 style="width: {{ (($step - 1) / ($totalSteps - 1)) * 100 }}%"></div>
        </div>
    </div>

    {{-- Success banner --}}
    @if ($saved)
    <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div>
            <p class="font-semibold text-emerald-800">Profile saved!</p>
            <p class="text-sm text-emerald-600">OSCA ID: <strong>{{ $senior?->osca_id }}</strong></p>
        </div>
        <a href="{{ $senior ? route('surveys.qol.create', $senior->id) : '#' }}"
           class="ml-auto px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700">
            + Take QoL Survey →
        </a>
    </div>
    @endif

    {{-- ── Form Card ── --}}
    <form wire:submit.prevent="{{ $step < $totalSteps ? 'nextStep' : 'save' }}">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">

        {{-- Validation errors --}}
        @if ($errors->any())
        <div class="px-5 py-3 bg-red-50 border-b border-red-200">
            <ul class="text-sm text-red-600 space-y-0.5">
                @foreach ($errors->all() as $err)
                    <li>• {{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="p-5">

            {{-- ─── STEP 1: Identifying Information ─── --}}
            @if ($step === 1)
            <h3 class="font-display text-xl text-slate-800 mb-5">I. Identifying Information</h3>
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="firstName" placeholder="Juan"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                    @error('firstName') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Middle Name</label>
                    <input type="text" wire:model="middleName" placeholder="Santos"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="lastName" placeholder="Dela Cruz"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                    @error('lastName') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Name Extension</label>
                    <input type="text" wire:model="nameExtension" placeholder="Jr., Sr., II"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Barangay <span class="text-red-500">*</span></label>
                    <select wire:model="barangay"
                            class="w-full text-sm border rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 {{ $errors->has('barangay') ? 'border-red-400 bg-red-50' : 'border-slate-200' }}">
                        <option value="">Select barangay…</option>
                        @foreach (\App\Models\SeniorCitizen::barangayList() as $b)
                            <option value="{{ $b }}">{{ $b }}</option>
                        @endforeach
                    </select>
                    @error('barangay') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Date of Birth <span class="text-red-500">*</span></label>
                    <input type="date" wire:model="dateOfBirth" max="{{ date('Y-m-d', strtotime('-60 years')) }}"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 {{ $errors->has('dateOfBirth') ? 'border-red-400' : '' }}">
                    @error('dateOfBirth') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Gender</label>
                    <select wire:model="gender"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                        <option value="">Select…</option>
                        <option>Male</option><option>Female</option><option>Prefer not to say</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Marital Status</label>
                    <select wire:model="maritalStatus"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                        <option value="">Select…</option>
                        @foreach (['Single','Married','Widowed','Separated'] as $ms)
                            <option>{{ $ms }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Religion</label>
                    <select wire:model="religion"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                        <option value="">Select…</option>
                        @foreach (['Roman Catholic','Iglesia ni Cristo','Islam','Protestant / Evangelical','Seventh-day Adventist','Jehovah\'s Witness','Aglipayan','Other'] as $rel)
                            <option>{{ $rel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Blood Type</label>
                    <select wire:model="bloodType"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                        <option value="">Unknown</option>
                        @foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bt)
                            <option>{{ $bt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Contact Number</label>
                    <input type="text" wire:model="contactNumber" placeholder="09XX XXX XXXX"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Place of Birth</label>
                    <input type="text" wire:model="placeOfBirth"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
            @endif

            {{-- ─── STEP 2: Family Composition ─── --}}
            @if ($step === 2)
            <h3 class="font-display text-xl text-slate-800 mb-5">II. Family Composition</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Number of Children</label>
                    <input type="number" wire:model="numChildren" min="0"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Number of Working Children</label>
                    <input type="number" wire:model="numWorkingChildren" min="0"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Household Size (total members)</label>
                    <input type="number" wire:model="householdSize" min="1"
                           class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Financially Supported by Children?</label>
                    <select wire:model="childFinancialSupport"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                        <option value="">Select…</option>
                        <option>Yes</option><option>No</option><option>Occasional</option><option>N/A</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Spouse / Partner Working?</label>
                    <div class="flex gap-3">
                        @foreach (['Yes','No','Deceased','N/A'] as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="spouseWorking" value="{{ $opt }}" class="accent-teal-600">
                            <span class="text-sm text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            {{-- ─── STEP 3: Education / HR ─── --}}
            @if ($step === 3)
            <h3 class="font-display text-xl text-slate-800 mb-5">III. Education / HR Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Educational Attainment</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['Not Attended School','Elementary Level','Elementary Graduate','High School Level','High School Graduate','Vocational','College Level','College Graduate','Post Graduate'] as $edu)
                        <label class="flex items-center gap-2 cursor-pointer p-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors {{ $educationalAttainment === $edu ? 'border-teal-400 bg-teal-50' : '' }}">
                            <input type="radio" wire:model="educationalAttainment" value="{{ $edu }}" class="accent-teal-600">
                            <span class="text-xs text-slate-700">{{ $edu }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Areas of Specialization / Technical Skills (check all applicable)</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($this->specializationOptions() as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="specialization" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Community Service and Involvement (check all applicable)</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->communityServiceOptions() as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="communityService" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            {{-- ─── STEP 4: Dependency Profile ─── --}}
            @if ($step === 4)
            <h3 class="font-display text-xl text-slate-800 mb-5">IV. Dependency Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Living / Residing with (check all applicable)</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['Alone','Spouse','Children','Grandchildren','Relative(s)','Friend(s)','Care Institution'] as $opt)
                        <label class="flex items-center gap-2 cursor-pointer p-2 border border-slate-200 rounded-lg hover:bg-slate-50">
                            <input type="checkbox" wire:model="livingWith" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Household Condition (check all applicable)</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ([
                            'No privacy','Overcrowded in home','Informal settler','No permanent house',
                            'High cost of rent','Longing for independent living quiet atmosphere',
                            'House is owned','Land is not owned','Shared with relatives',
                            'Government-Provided',
                        ] as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="householdCondition" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            {{-- ─── STEP 5: Economic Profile ─── --}}
            @if ($step === 5)
            <h3 class="font-display text-xl text-slate-800 mb-5">V. Economic Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Source of Income and Assistance</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($this->incomeSourceOptions() as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="incomeSource" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Monthly Income Range</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['Below 1,000','1,000 - 5,000','5,000 - 10,000','10,000 - 20,000','20,000 - 30,000','30,000 - 40,000','40,000 - 50,000','50,000 - 60,000','60,000 and above'] as $inc)
                        <label class="flex items-center gap-2 cursor-pointer p-2 border border-slate-200 rounded-lg hover:bg-slate-50 {{ $monthlyIncomeRange===$inc ? 'border-teal-400 bg-teal-50' : '' }}">
                            <input type="radio" wire:model="monthlyIncomeRange" value="{{ $inc }}" class="accent-teal-600">
                            <span class="text-xs text-slate-700">₱{{ $inc }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Real / Immovable Assets</label>
                        <div class="space-y-1">
                            @foreach (['House','Lot/Farmland','House and Lot','Commercial Building','Apartment/Rental Unit','Fishpond/Resort','Agricultural Land/Farm','No known assets'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="realAssets" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Personal / Movable Assets</label>
                        <div class="space-y-1">
                            @foreach (['Automobile','Motorcycle','Bicycle','Personal Computer','Laptop','Tablet','Mobile Phone','Heavy Equipment','Appliances (Refrigerator / TV / Washing Machine)','No known assets'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="movableAssets" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Problems / Needs Commonly Encountered</label>
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
                            <input type="checkbox" wire:model="problemsNeeds" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                    @if (in_array('Others', $problemsNeeds))
                    <div class="mt-2">
                        <input type="text" wire:model="problemsNeedsOther"
                               placeholder="Please specify..."
                               class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- ─── STEP 6: Health Profile ─── --}}
            @if ($step === 6)
            <h3 class="font-display text-xl text-slate-800 mb-5">VI. Health Profile</h3>
            <div class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Medical Concerns (check all applicable)</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($this->medicalConcernOptions() as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="medicalConcern" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-2">Social / Emotional Concerns (check all applicable)</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->socialEmotionalConcernOptions() as $opt)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="socialEmotionalConcern" value="{{ $opt }}" class="accent-teal-600 rounded">
                            <span class="text-xs text-slate-700">{{ $opt }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Dental Concern (check all applicable)</label>
                        <div class="space-y-1">
                            @foreach (['Needs dental care','Tooth decay/cavities','Gum disease','Tooth loss/missing teeth','Healthy Teeth'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="dentalConcern" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Optical / Vision (check all applicable)</label>
                        <div class="space-y-1">
                            @foreach (['Eye impairment','Needs eye care','Blurred vision','Cataract','Glaucoma','Healthy Eyes'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="opticalConcern" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Hearing (check all applicable)</label>
                        <div class="space-y-1">
                            @foreach (['Hearing impairment','Partial hearing loss','Difficulty hearing conversations','Uses hearing aid','Healthy Hearing'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="hearingConcern" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Healthcare Access Difficulty (check all applicable)</label>
                        <div class="space-y-1">
                            @foreach (['High cost of medicines','Lack of medicines','Lack of medical attention','Difficulty accessing health facilities','Lack of transportation to clinics','Long waiting time','Healthcare is accessible'] as $opt)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="healthcareDifficulty" value="{{ $opt }}" class="accent-teal-600 rounded">
                                <span class="text-xs text-slate-700">{{ $opt }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="p-4 bg-slate-50 rounded-xl border border-slate-200">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="hasMedicalCheckup" class="accent-teal-600 w-4 h-4 rounded">
                        <span class="text-sm font-medium text-slate-700">Has scheduled medical / physical check-up</span>
                    </label>
                    @if ($hasMedicalCheckup)
                    <div class="mt-3 ml-7">
                        <label class="block text-xs text-slate-500 mb-2">How often?</label>
                        <div class="flex flex-wrap gap-4">
                            @foreach (['Every 3 months', 'Every 6 months', 'Others'] as $sch)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="checkupSchedule" value="{{ $sch }}" class="accent-teal-600">
                                <span class="text-sm text-slate-700">{{ $sch }}</span>
                            </label>
                            @endforeach
                        </div>
                        @if ($checkupSchedule === 'Others')
                        <div class="mt-2">
                            <input type="text" wire:model="checkupScheduleOther"
                                   placeholder="Please specify schedule..."
                                   class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            @endif

        </div>

        {{-- ── Footer Navigation ── --}}
        <div class="border-t border-slate-100 px-5 py-4 flex items-center gap-3">
            @if ($step > 1)
            <button wire:click="prevStep"
                    class="px-4 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                ← Back
            </button>
            @endif

            <div class="ml-auto flex gap-3">
                @if ($step < $totalSteps)
                <button wire:click="nextStep"
                        class="px-5 py-2 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 transition-colors shadow-sm">
                    Next →
                </button>
                @else
                <button wire:click="save"
                        class="px-6 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition-colors shadow-sm">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        Save Profile
                    </span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    </form>
</div>
