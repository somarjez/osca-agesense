<?php

namespace App\Livewire\Surveys;

use App\Models\ProfileDraft;
use App\Models\SeniorCitizen;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Rule;
use Livewire\Component;

class ProfileSurvey extends Component
{
    public ?SeniorCitizen $senior = null;

    public ?ProfileDraft $draft = null;

    public int $step = 1;

    public int $totalSteps = 6;

    public bool $saved = false;

    // ── I. Identifying Information ────────────────────────────────────────────
    #[Rule('required|string|max:100')]
    public string $firstName = '';

    public string $middleName = '';

    #[Rule('required|string|max:100')]
    public string $lastName = '';

    public string $nameExtension = '';

    #[Rule('required')]
    public string $barangay = '';

    #[Rule('required|date')]
    public string $dateOfBirth = '';

    public string $contactNumber = '';

    public string $placeOfBirth = '';

    public string $maritalStatus = '';

    public string $gender = '';

    public string $religion = '';

    public string $ethnicOrigin = '';

    public string $bloodType = '';

    // ── II. Family Composition ────────────────────────────────────────────────
    public int $numChildren = 0;

    public int $numWorkingChildren = 0;

    public string $childFinancialSupport = '';

    public string $spouseWorking = '';

    public int $householdSize = 1;

    // ── III. Education / HR Profile ───────────────────────────────────────────
    public string $educationalAttainment = '';

    public array $specialization = [];

    public array $communityService = [];

    // ── IV. Dependency Profile ────────────────────────────────────────────────
    public array $livingWith = [];

    public array $householdCondition = [];

    // ── V. Economic Profile ───────────────────────────────────────────────────
    public array $incomeSource = [];

    public array $realAssets = [];

    public array $movableAssets = [];

    public string $monthlyIncomeRange = '';

    public array $problemsNeeds = [];

    public string $problemsNeedsOther = '';

    // ── VI. Health Profile ────────────────────────────────────────────────────
    public array $medicalConcern = [];

    public array $dentalConcern = [];

    public array $opticalConcern = [];

    public array $hearingConcern = [];

    public array $socialEmotionalConcern = [];

    public array $healthcareDifficulty = [];

    public bool $hasMedicalCheckup = false;

    public string $checkupSchedule = '';

    public string $checkupScheduleOther = '';

    // ── Data Privacy / Consent ────────────────────────────────────────────────
    public string $consentGivenAt = '';

    public string $consentMethod = '';

    /** property => the mutually-exclusive "none/healthy" option in that checkbox group */
    public const EXCLUSIVE_TOKENS = [
        'medicalConcern' => 'Physically Healthy',
        'socialEmotionalConcern' => 'Living in a healthy environment',
        'dentalConcern' => 'Healthy Teeth',
        'opticalConcern' => 'Healthy Eyes',
        'hearingConcern' => 'Healthy Hearing',
        'healthcareDifficulty' => 'Healthcare is accessible',
        'problemsNeeds' => 'Limited problems encountered',
        'realAssets' => 'No known assets',
        'movableAssets' => 'No known assets',
        'livingWith' => 'Alone',
    ];

    /**
     * Groups pre-selected to their exclusive token on a brand-new profile.
     * livingWith is intentionally excluded: defaulting "Alone" would feed the
     * ML pipeline's lives_alone tag and skew untouched records as high-need.
     */
    private const CREATE_DEFAULT_GROUPS = [
        'medicalConcern', 'socialEmotionalConcern', 'dentalConcern', 'opticalConcern',
        'hearingConcern', 'healthcareDifficulty', 'problemsNeeds', 'realAssets', 'movableAssets',
    ];

    public function mount(?int $seniorId = null): void
    {
        if ($seniorId) {
            $this->senior = SeniorCitizen::findOrFail($seniorId);
            $this->authorize('update', $this->senior);
            $this->draft = ProfileDraft::where('senior_citizen_id', $seniorId)->first();
            if ($this->draft) {
                $this->populateFromDraft($this->draft);

                return;
            }
            $this->populateFromModel($this->senior);
        } else {
            $this->authorize('create', SeniorCitizen::class);
            $this->draft = ProfileDraft::whereNull('senior_citizen_id')
                ->where('created_by', Auth::id())
                ->latest()
                ->first();
            if ($this->draft) {
                $this->populateFromDraft($this->draft);
            } else {
                $this->applyCreateDefaults();
            }
        }
    }

    private function applyCreateDefaults(): void
    {
        foreach (self::CREATE_DEFAULT_GROUPS as $prop) {
            $this->$prop = [self::EXCLUSIVE_TOKENS[$prop]];
        }
    }

    /**
     * An exclusive "none/healthy" token can't coexist with real answers: the
     * client-side helper enforces this in the UI, but the persisted value must
     * not depend on JS, so drop the token whenever it arrives mixed in.
     */
    private function sanitizeExclusiveGroups(): void
    {
        foreach (self::EXCLUSIVE_TOKENS as $prop => $token) {
            if (count($this->$prop) > 1 && in_array($token, $this->$prop, true)) {
                $this->$prop = array_values(array_filter($this->$prop, fn ($v) => $v !== $token));
            }
        }
    }

    public function nextStep(): void
    {
        $this->validateCurrentStep();
        if ($this->step < $this->totalSteps) {
            $this->step++;
        }
    }

    public function prevStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function goToStep(int $step): void
    {
        $this->step = $step;
    }

    public function save(): void
    {
        // Livewire network calls bypass HTTP route middleware, so enforce policy here
        // (single source of truth: SeniorCitizenPolicy, same role gate as before).
        $this->senior
            ? $this->authorize('update', $this->senior)
            : $this->authorize('create', SeniorCitizen::class);

        $this->validateCurrentStep();
        $this->sanitizeExclusiveGroups();

        $data = [
            'first_name' => $this->firstName,
            'middle_name' => $this->middleName ?: null,
            'last_name' => $this->lastName,
            'name_extension' => $this->nameExtension ?: null,
            'barangay' => $this->barangay,
            'date_of_birth' => $this->dateOfBirth,
            'contact_number' => $this->contactNumber ?: null,
            'place_of_birth' => $this->placeOfBirth ?: null,
            'marital_status' => $this->maritalStatus ?: null,
            'gender' => $this->gender ?: null,
            'religion' => $this->religion ?: null,
            'ethnic_origin' => $this->ethnicOrigin ?: null,
            'blood_type' => $this->bloodType ?: null,
            'num_children' => $this->numChildren,
            'num_working_children' => $this->numWorkingChildren,
            'child_financial_support' => $this->childFinancialSupport ?: null,
            'spouse_working' => $this->spouseWorking ?: null,
            'household_size' => $this->householdSize,
            'educational_attainment' => $this->educationalAttainment ?: null,
            'specialization' => $this->specialization ?: null,
            'community_service' => $this->communityService ?: null,
            'living_with' => $this->livingWith ?: null,
            'household_condition' => $this->householdCondition ?: null,
            'income_source' => $this->incomeSource ?: null,
            'real_assets' => $this->realAssets ?: null,
            'movable_assets' => $this->movableAssets ?: null,
            'monthly_income_range' => $this->monthlyIncomeRange ?: null,
            'problems_needs' => $this->buildProblemsNeeds(),
            'medical_concern' => $this->medicalConcern ?: null,
            'dental_concern' => $this->dentalConcern ?: null,
            'optical_concern' => $this->opticalConcern ?: null,
            'hearing_concern' => $this->hearingConcern ?: null,
            'social_emotional_concern' => $this->socialEmotionalConcern ?: null,
            'healthcare_difficulty' => $this->healthcareDifficulty ?: null,
            'has_medical_checkup' => $this->hasMedicalCheckup,
            'checkup_schedule' => $this->buildCheckupSchedule(),
            'encoded_by' => Auth::user()?->name,
            'consent_given_at' => $this->consentGivenAt ?: null,
            'consent_method' => $this->consentMethod ?: null,
        ];

        if ($this->senior) {
            $this->senior->update($data);
        } else {
            $data['osca_id'] = SeniorCitizen::generateOscaId($this->barangay);
            $this->senior = SeniorCitizen::create($data);
        }

        $this->saved = true;
        $this->draft?->delete();
        $this->draft = null;
        $this->dispatch('profile-saved', seniorId: $this->senior->id);
        session()->flash('success', "Senior citizen profile saved. OSCA ID: {$this->senior->osca_id}");
    }

    public function saveDraft(): void
    {
        $this->sanitizeExclusiveGroups();

        $payload = [
            'senior_citizen_id' => $this->senior?->id,
            'created_by' => Auth::id(),
            'step' => $this->step,
            'data' => $this->currentData(),
        ];

        $this->draft = $this->senior
            ? ProfileDraft::updateOrCreate(['senior_citizen_id' => $this->senior->id], $payload)
            : ($this->draft ? tap($this->draft)->update($payload) : ProfileDraft::create($payload));

        session()->flash('success', 'Draft saved.');
        $this->redirect($this->senior
            ? route('seniors.edit', $this->senior)
            : route('surveys.profile.create'));
    }

    private function validateCurrentStep(): void
    {
        match ($this->step) {
            1 => $this->validate($this->step1Rules(), $this->step1Messages()),
            default => null,
        };
    }

    private function step1Rules(): array
    {
        return [
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'barangay' => 'required|string',
            'dateOfBirth' => 'required|date|after_or_equal:1900-01-01|before:today',
            'consentGivenAt' => 'nullable|date|after_or_equal:1900-01-01|before_or_equal:today|required_if:consentMethod,verbal,written,digital',
        ];
    }

    private function step1Messages(): array
    {
        return [
            'dateOfBirth.after_or_equal' => 'Date of birth must be in the year 1900 or later.',
            'dateOfBirth.before' => 'Date of birth must be in the past.',
            'consentGivenAt.after_or_equal' => 'Consent date must be in the year 1900 or later.',
            'consentGivenAt.before_or_equal' => 'Consent date cannot be in the future.',
        ];
    }

    public function updatedDateOfBirth(): void
    {
        $this->validateOnly('dateOfBirth', $this->step1Rules(), $this->step1Messages());
    }

    public function updatedConsentGivenAt(): void
    {
        $this->validateOnly('consentGivenAt', $this->step1Rules(), $this->step1Messages());
    }

    private function populateFromModel(SeniorCitizen $s): void
    {
        $this->firstName = $s->first_name;
        $this->middleName = $s->middle_name ?? '';
        $this->lastName = $s->last_name;
        $this->nameExtension = $s->name_extension ?? '';
        $this->barangay = $s->barangay;
        $this->dateOfBirth = $s->date_of_birth?->format('Y-m-d') ?? '';
        $this->contactNumber = $s->contact_number ?? '';
        $this->placeOfBirth = $s->place_of_birth ?? '';
        $this->maritalStatus = $s->marital_status ?? '';
        $this->gender = $s->gender ?? '';
        $this->religion = $s->religion ?? '';
        $this->ethnicOrigin = $s->ethnic_origin ?? '';
        $this->bloodType = $s->blood_type ?? '';
        $this->numChildren = $s->num_children;
        $this->numWorkingChildren = $s->num_working_children;
        $this->childFinancialSupport = $s->child_financial_support ?? '';
        $this->spouseWorking = $s->spouse_working ?? '';
        $this->householdSize = $s->household_size;
        $this->educationalAttainment = $s->educational_attainment ?? '';
        $this->specialization = $s->specialization ?? [];
        $this->communityService = $s->community_service ?? [];
        $this->livingWith = $s->living_with ?? [];
        $this->householdCondition = $s->household_condition ?? [];
        $this->incomeSource = $s->income_source ?? [];
        $this->realAssets = $s->real_assets ?? [];
        $this->movableAssets = $s->movable_assets ?? [];
        $this->monthlyIncomeRange = $s->monthly_income_range ?? '';
        [$this->problemsNeeds, $this->problemsNeedsOther] = $this->parseProblemsNeeds($s->problems_needs ?? []);
        $this->medicalConcern = $s->medical_concern ?? [];
        $this->dentalConcern = $s->dental_concern ?? [];
        $this->opticalConcern = $s->optical_concern ?? [];
        $this->hearingConcern = $s->hearing_concern ?? [];
        $this->socialEmotionalConcern = $s->social_emotional_concern ?? [];
        $this->healthcareDifficulty = $s->healthcare_difficulty ?? [];
        $this->hasMedicalCheckup = $s->has_medical_checkup;
        [$this->checkupSchedule, $this->checkupScheduleOther] = $this->parseCheckupSchedule($s->checkup_schedule ?? '');
        $this->consentGivenAt = $s->consent_given_at?->format('Y-m-d') ?? '';
        $this->consentMethod = $s->consent_method ?? '';
    }

    private function currentData(): array
    {
        return [
            'firstName' => $this->firstName, 'middleName' => $this->middleName,
            'lastName' => $this->lastName, 'nameExtension' => $this->nameExtension,
            'barangay' => $this->barangay, 'dateOfBirth' => $this->dateOfBirth,
            'contactNumber' => $this->contactNumber, 'placeOfBirth' => $this->placeOfBirth,
            'maritalStatus' => $this->maritalStatus, 'gender' => $this->gender,
            'religion' => $this->religion, 'ethnicOrigin' => $this->ethnicOrigin,
            'bloodType' => $this->bloodType,
            'numChildren' => $this->numChildren, 'numWorkingChildren' => $this->numWorkingChildren,
            'childFinancialSupport' => $this->childFinancialSupport, 'spouseWorking' => $this->spouseWorking,
            'householdSize' => $this->householdSize,
            'educationalAttainment' => $this->educationalAttainment,
            'specialization' => $this->specialization, 'communityService' => $this->communityService,
            'livingWith' => $this->livingWith, 'householdCondition' => $this->householdCondition,
            'incomeSource' => $this->incomeSource, 'realAssets' => $this->realAssets,
            'movableAssets' => $this->movableAssets, 'monthlyIncomeRange' => $this->monthlyIncomeRange,
            'problemsNeeds' => $this->problemsNeeds, 'problemsNeedsOther' => $this->problemsNeedsOther,
            'medicalConcern' => $this->medicalConcern, 'dentalConcern' => $this->dentalConcern,
            'opticalConcern' => $this->opticalConcern, 'hearingConcern' => $this->hearingConcern,
            'socialEmotionalConcern' => $this->socialEmotionalConcern,
            'healthcareDifficulty' => $this->healthcareDifficulty,
            'hasMedicalCheckup' => $this->hasMedicalCheckup, 'checkupSchedule' => $this->checkupSchedule,
            'checkupScheduleOther' => $this->checkupScheduleOther,
            'consentGivenAt' => $this->consentGivenAt, 'consentMethod' => $this->consentMethod,
        ];
    }

    private function populateFromDraft(ProfileDraft $draft): void
    {
        foreach ($draft->data as $prop => $value) {
            if (property_exists($this, $prop)) {
                $this->$prop = $value;
            }
        }
        $this->step = $draft->step;
    }

    private function buildCheckupSchedule(): ?string
    {
        if (! $this->hasMedicalCheckup || $this->checkupSchedule === '') {
            return null;
        }
        if ($this->checkupSchedule === 'Others') {
            return $this->checkupScheduleOther !== ''
                ? 'Others: '.trim($this->checkupScheduleOther)
                : 'Others';
        }

        return $this->checkupSchedule;
    }

    private function parseCheckupSchedule(string $raw): array
    {
        if (str_starts_with($raw, 'Others:')) {
            return ['Others', trim(substr($raw, 7))];
        }

        return [$raw, ''];
    }

    private function buildProblemsNeeds(): ?array
    {
        $arr = array_filter($this->problemsNeeds, fn ($v) => $v !== 'Others');
        if (in_array('Others', $this->problemsNeeds)) {
            $arr[] = $this->problemsNeedsOther !== ''
                ? 'Others: '.trim($this->problemsNeedsOther)
                : 'Others';
        }

        return array_values($arr) ?: null;
    }

    private function parseProblemsNeeds(array $raw): array
    {
        $other = '';
        $normalized = [];
        foreach ($raw as $v) {
            if (str_starts_with($v, 'Others:')) {
                $other = trim(substr($v, 7));
                $normalized[] = 'Others';
            } else {
                $normalized[] = $v;
            }
        }

        return [$normalized, $other];
    }

    public static function specializationOptions(): array
    {
        return [
            'Medical', 'Teaching', 'Legal Services', 'Dental', 'Counseling', 'Administrative',
            'Farming', 'Fishing', 'Cooking', 'Arts/Crafts', 'Engineering', 'Beautycare',
            'Housekeeping', 'Carpenter', 'Plumber', 'Barber/Hairdresser', 'Mason',
            'Sewing/Tailoring', 'Driving', 'Small Business', 'Entrepreneurship',
            'Computer/Digital Skills', 'Caregiving', 'Social Service', 'Factory Worker',
            'Cashier', 'Office Worker', 'Photographer', 'Tourist Guide',
        ];
    }

    public static function communityServiceOptions(): array
    {
        return [
            'Resource Volunteer', 'Community Beautification', 'Community Leader',
            'Friendly Visits', 'Religious', 'Counseling/Referral', 'Sponsorship',
            'Senior Citizen Association Member', 'Barangay Volunteer',
            'Health/Wellness Volunteer', 'Disaster Response Volunteer',
        ];
    }

    public static function incomeSourceOptions(): array
    {
        return [
            'Own earnings/salary', 'Own pension', 'Dependent on children/relatives',
            'Spouse salary', 'Spouse pension', 'Rentals/Sharecrops', 'Savings',
            'Livestock/Farm', 'Fishing', 'Insurance', 'Business',
        ];
    }

    public static function medicalConcernOptions(): array
    {
        return [
            'Hypertension', 'Diabetes', 'Arthritis / Gout', 'Coronary Heart Disease',
            'Chronic Kidney Disease', 'Alzheimer\'s / Dementia', 'COPD',
            'Asthma', 'Stroke', 'Osteoporosis', 'Parkinson\'s Disease', 'Cancer',
            'Tuberculosis (TB)', 'UTI', 'Anemia', 'Physical Disability',
            'Mental Health Condition (Depression / Anxiety)',
            'Prostate', 'Pneumonia', 'Sinusitis',
            'Other Chronic Disease', 'Physically Healthy',
        ];
    }

    public static function socialEmotionalConcernOptions(): array
    {
        return [
            'Feeling Neglect/Rejection',
            'Feeling Helplessness/Worthlessness',
            'Feeling/Loneliness/Isolation',
            'Feeling Depressed/Anxiety',
            'Lack social support',
            'Lack leisure activities',
            'Living in a healthy environment',
        ];
    }

    public function render()
    {
        return view('livewire.surveys.profile-survey');
    }
}
