<?php

namespace App\Livewire\Surveys;

use App\Models\ProfileDraft;
use App\Models\SeniorCitizen;
use App\Support\NameRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Rule;
use Livewire\Component;

class ProfileSurvey extends Component
{
    private const OFFICIAL_OSCA_ID_UNIQUE_MESSAGE = 'The Official OSCA ID is already assigned to another senior citizen. Please enter a unique Official OSCA ID.';

    public ?SeniorCitizen $senior = null;

    public ?ProfileDraft $draft = null;

    /**
     * Fields the user has actually interacted with, keyed by property name.
     * Must be public so Livewire persists it across requests (a protected
     * property isn't part of the component snapshot and would reset on
     * every round-trip). Used so a class-default value that merely looks
     * like a real answer (numChildren=0, householdSize=1) isn't counted as
     * "filled" by requiredFieldStatus() until the user touches it.
     *
     * @var array<string, bool>
     */
    public array $touchedFields = [];

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

    /** Official OSCA-ID issued by the OSCA office; optional, blank until assigned. */
    public string $officialOscaId = '';

    #[Rule('required|date')]
    public string $dateOfBirth = '';

    /** When the senior was registered with the OSCA office; defaults to today for new profiles. */
    public string $registrationDate = '';

    public string $contactNumber = '';

    public string $placeOfBirth = '';

    public string $maritalStatus = '';

    public string $gender = '';

    public string $religion = '';

    public string $ethnicOrigin = '';

    public string $bloodType = '';

    // ── Lifecycle status (edit-only; new profiles are always 'active') ─────────
    public string $status = 'active';

    public string $dateOfDeath = '';

    public string $deceasedNote = '';

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

    public function mount(?int $seniorId = null, ?int $draftId = null): void
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

            return;
        }

        $this->authorize('create', SeniorCitizen::class);

        if ($draftId) {
            // Resume THIS specific draft (from the Drafts list) — may belong to
            // any staff member, not just the current user; visibility there is
            // deliberately shared, not per-user.
            $this->draft = ProfileDraft::whereNull('senior_citizen_id')->findOrFail($draftId);
            $this->populateFromDraft($this->draft);

            return;
        }

        // No explicit draft requested: always start a blank new profile. This
        // used to silently resume the current user's single latest draft, which
        // meant a second in-progress registration could never be started — every
        // "New Profile" click just dumped you back into whatever draft #1 was.
        // Now that the Drafts list is the dedicated way to resume prior work
        // (by its own id), "New Profile" should always mean a fresh form, so
        // any number of drafts can exist side by side.
        $this->applyCreateDefaults();
    }

    private function applyCreateDefaults(): void
    {
        foreach (self::CREATE_DEFAULT_GROUPS as $prop) {
            $this->$prop = [self::EXCLUSIVE_TOKENS[$prop]];
        }
        $this->registrationDate = now()->format('Y-m-d');
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

    /**
     * Required-fields-filled ratio across the entire profile — requiredFieldStatus()'s
     * denominator spans every step's required fields, not just the current one, so this
     * does not reproduce the old "Step 1 alone hits 100% while on Step 2" symptom.
     * Previously this was wizard step position ((step-1)/(totalSteps-1)), which could
     * reach 100% via goToStep() jumping straight to the final step with every required
     * field still blank — the reported bug.
     */
    public function completionPercent(): int
    {
        [$filled, $total] = $this->requiredFieldStatus();

        return $total === 0 ? 0 : (int) round(($filled / $total) * 100);
    }

    /**
     * Whether the record can actually be submitted right now — deliberately
     * NOT derived from completionPercent() above (wizard position). Every
     * required field across all six steps must be filled, independent of
     * which step the user is currently viewing or has visited.
     */
    public function stepStatusText(): string
    {
        [$filled, $total, $missing] = $this->requiredFieldStatus();

        return match (true) {
            $filled === 0 => "Let's get started.",
            $filled < $total => 'In progress. Missing: '.implode(', ', array_map('ucfirst', $missing)).'.',
            $this->crossFieldViolations() !== [] => 'Fix conflicting answers: '.implode(', ', $this->crossFieldViolations()).'.',
            default => 'Ready to submit.',
        };
    }

    /** Whether every required field is filled and no contradictory answers remain, so Save can be enabled from any step. */
    public function canSave(): bool
    {
        [$filled, $total] = $this->requiredFieldStatus();

        return $filled === $total && $this->crossFieldViolations() === [];
    }

    /**
     * Cross-field contradictions that Laravel's per-field rules can't express
     * on their own (each check here mirrors a validation closure in
     * step2Rules()/step4Rules() etc. — this is purely for driving the
     * "ready to submit" UI, the closures remain the actual enforcement).
     *
     * @return array<int, string>
     */
    private function crossFieldViolations(): array
    {
        $violations = [];

        if ($this->numWorkingChildren > $this->numChildren) {
            $violations[] = 'working children cannot exceed number of children';
        }

        if ((int) $this->numChildren === 0 && in_array($this->childFinancialSupport, ['Yes', 'Occasional'], true)) {
            $violations[] = 'child financial support conflicts with 0 children';
        }

        if (in_array('Alone', $this->livingWith, true)
            && (in_array('Overcrowded in home', $this->householdCondition, true) || in_array('Shared with relatives', $this->householdCondition, true))) {
            $violations[] = 'living alone conflicts with household condition';
        }

        $spouseWorkingAllowed = $this->spouseWorkingAllowedValues();
        if ($spouseWorkingAllowed !== null && $this->spouseWorking !== '' && ! in_array($this->spouseWorking, $spouseWorkingAllowed, true)) {
            $violations[] = 'spouse employment status conflicts with marital status';
        }

        if (in_array('Spouse', $this->livingWith, true)
            && (in_array($this->maritalStatus, ['Single', 'Widowed'], true) || $this->spouseWorking === 'Deceased')) {
            $violations[] = 'living with spouse conflicts with marital status or spouse employment status';
        }

        if (in_array($this->maritalStatus, ['Single', 'Widowed'], true)
            && (in_array('Spouse salary', $this->incomeSource, true) || in_array('Spouse pension', $this->incomeSource, true))) {
            $violations[] = 'spouse income source conflicts with marital status';
        }

        if (in_array('Alone', $this->livingWith, true) && (int) $this->householdSize !== 1) {
            $violations[] = 'living alone conflicts with household size greater than 1';
        }

        if ((int) $this->householdSize === 1 && $this->livingWith !== [] && ! in_array('Alone', $this->livingWith, true)) {
            $violations[] = 'household size of 1 conflicts with living arrangement other than alone';
        }

        if ($this->houseAndLotOwnershipConflict() && in_array('House and Lot', $this->realAssets, true)) {
            $violations[] = 'house and lot asset conflicts with household condition (house owned, land not owned)';
        }

        return $violations;
    }

    /**
     * numChildren/numWorkingChildren/householdSize default to 0/0/1 — values
     * indistinguishable from a real answer once rendered in their <input
     * type="number"> fields. On a new profile (no $senior loaded) they must
     * not count as "filled" until the user actually edits them.
     */
    public function updatedNumChildren(): void
    {
        $this->touchedFields['numChildren'] = true;
    }

    public function updatedNumWorkingChildren(): void
    {
        $this->touchedFields['numWorkingChildren'] = true;
    }

    public function updatedHouseholdSize(): void
    {
        $this->touchedFields['householdSize'] = true;
    }

    /**
     * The 9 CREATE_DEFAULT_GROUPS checkbox arrays are pre-seeded with an exclusive
     * "none/healthy" token on mount (applyCreateDefaults()) — indistinguishable from
     * a real checked box once rendered, same class of bug as the numeric defaults
     * above. Livewire fires this generic hook for every property change including
     * these arrays, whether from a checkbox's wire:model or the exclusiveGroup()
     * Alpine helper's direct $wire[prop] write, alongside the specific hooks above.
     */
    public function updated(string $property): void
    {
        if (in_array($property, self::CREATE_DEFAULT_GROUPS, true)) {
            $this->touchedFields[$property] = true;
        }
    }

    /** @return array{0: int, 1: int, 2: array<int, string>} [required fields filled, required fields total, missing field labels]. */
    private function requiredFieldStatus(): array
    {
        $filled = 0;
        $total = 0;
        $missing = [];
        $labels = $this->validationAttributes();
        $ambiguousCreateDefaults = array_merge(
            ['numChildren', 'numWorkingChildren', 'householdSize'],
            self::CREATE_DEFAULT_GROUPS,
        );
        foreach ($this->allStepsRules() as $field => $rule) {
            $tokens = is_array($rule) ? $rule : explode('|', (string) $rule);
            if (! in_array('required', $tokens, true)) {
                continue;
            }
            if ($field === 'status' && ! $this->senior) {
                // Edit-only field (see property declaration above): not rendered on
                // create, so it shouldn't affect the create wizard's progress.
                continue;
            }
            $total++;
            $value = $this->{$field} ?? null;
            $isFilled = is_array($value) ? count($value) > 0 : ($value !== null && $value !== '');
            if ($isFilled && ! $this->senior && in_array($field, $ambiguousCreateDefaults, true) && empty($this->touchedFields[$field])) {
                $isFilled = false;
            }
            if ($isFilled) {
                $filled++;
            } else {
                $missing[] = $labels[$field] ?? $field;
            }
        }

        return [$filled, $total, $missing];
    }

    /**
     * Seeds the client-side nameGuard() Alpine component (resources/js/app.js)
     * with the exact same pattern/message strings the server enforces in
     * step1Rules()/step1Messages() (via NameRules — single source of truth)
     * plus the current field values, so an Edit form carrying a legacy
     * invalid name shows its error immediately on load rather than only at
     * submit time.
     */
    public function nameGuardConfig(): array
    {
        return array_merge(NameRules::jsConfig(), [
            'values' => [
                'firstName' => $this->firstName,
                'middleName' => $this->middleName,
                'lastName' => $this->lastName,
                'nameExtension' => $this->nameExtension,
            ],
        ]);
    }

    public function save(): void
    {
        // Livewire network calls bypass HTTP route middleware, so enforce policy here
        // (single source of truth: SeniorCitizenPolicy, same role gate as before).
        $this->senior
            ? $this->authorize('update', $this->senior)
            : $this->authorize('create', SeniorCitizen::class);

        // save() performs a full multi-step save regardless of which step the
        // UI is currently on, so it must validate every step's rules here —
        // validating only the current step would let steps skipped via a
        // direct component call (bypassing client-side navigation) through.
        try {
            $this->validate(
                $this->allStepsRules(),
                $this->allStepsMessages(),
                $this->validationAttributes(),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->dispatch(
                'profile-validation-failed',
                title: 'Unable to save record',
                messages: collect($exception->validator->errors()->all())->unique()->values()->all(),
            );

            return;
        }
        $this->sanitizeExclusiveGroups();

        $data = [
            'first_name' => $this->firstName,
            'middle_name' => $this->middleName ?: null,
            'last_name' => $this->lastName,
            'name_extension' => $this->nameExtension ?: null,
            'barangay' => $this->barangay,
            'official_osca_id' => $this->officialOscaId !== '' ? $this->officialOscaId : null,
            'date_of_birth' => $this->dateOfBirth,
            'registration_date' => $this->registrationDate ?: null,
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
            'status' => $this->status,
            // Deceased-only fields; cleared on reactivation (or any non-deceased
            // status) so a status flip never leaves stale death info behind.
            'date_of_death' => $this->status === 'deceased' ? ($this->dateOfDeath ?: null) : null,
            'deceased_note' => $this->status === 'deceased' ? ($this->deceasedNote ?: null) : null,
        ];

        // Status control is edit-only, so this only ever fires for an existing
        // record whose status actually flipped (e.g. active -> deceased, or
        // reactivation). A brand-new profile always saves with the 'active'
        // default and never touches these audit columns.
        if ($this->senior && $this->senior->status !== $this->status) {
            $data['status_changed_by'] = Auth::user()?->name;
            $data['status_changed_at'] = now();
        }

        try {
            $this->persistProfile($data);
        } catch (QueryException $exception) {
            // The validation query and write are separate operations. If two
            // users race to claim the same ID, the database constraint is the
            // final authority and must still produce the friendly save error.
            if (! $this->isOfficialOscaIdUniqueViolation($exception)) {
                throw $exception;
            }

            $this->addError('officialOscaId', self::OFFICIAL_OSCA_ID_UNIQUE_MESSAGE);
            $this->dispatch(
                'profile-validation-failed',
                title: 'Unable to save record',
                messages: [self::OFFICIAL_OSCA_ID_UNIQUE_MESSAGE],
            );

            return;
        }

        $this->saved = true;
        $this->draft?->delete();
        $this->draft = null;
        $this->dispatch('profile-saved', seniorId: $this->senior->id);
        session()->flash('success', "Senior citizen profile saved. OSCA ID: {$this->senior->official_osca_id_display}");
    }

    public function saveDraft(): void
    {
        $this->sanitizeExclusiveGroups();

        $payload = [
            'senior_citizen_id' => $this->senior?->id,
            // Preserve the ORIGINAL drafter on repeat saves (the Drafts list's
            // "Started by" column should reflect who started it, not whoever
            // last happened to continue and save it — drafts are now shared,
            // not per-user).
            'created_by' => $this->draft?->created_by ?? Auth::id(),
            'step' => $this->step,
            'data' => $this->currentData(),
        ];

        $this->draft = $this->senior
            ? ProfileDraft::updateOrCreate(['senior_citizen_id' => $this->senior->id], $payload)
            : ($this->draft ? tap($this->draft)->update($payload) : ProfileDraft::create($payload));

        session()->flash('success', 'Draft saved.');
        $this->redirect($this->senior
            ? route('seniors.edit', $this->senior)
            // Always the SPECIFIC draft's own continue URL — not the generic
            // "latest draft for me" entry point, which would silently swap a
            // user onto their own unrelated draft if they were continuing
            // someone else's (drafts are visible to everyone, not per-user).
            : route('surveys.profile.draft.continue', $this->draft));
    }

    private function validateCurrentStep(): void
    {
        match ($this->step) {
            1 => $this->validate($this->step1Rules(), $this->step1Messages()),
            2 => $this->validate($this->step2Rules(), $this->step2Messages()),
            3 => $this->validate($this->step3Rules()),
            4 => $this->validate($this->step4Rules()),
            5 => $this->validate($this->step5Rules()),
            6 => $this->validate($this->step6Rules()),
            default => null,
        };
    }

    /** Full rule set across every step — used by save() so a direct call that
     * skips client-side step navigation still enforces every step's rules. */
    private function allStepsRules(): array
    {
        $rules = array_merge(
            $this->step1Rules(),
            $this->step2Rules(),
            $this->step3Rules(),
            $this->step4Rules(),
            $this->step5Rules(),
            $this->step6Rules(),
        );

        // On a full-record save (which can happen for an existing record whose
        // multi-select fields were populated by bulk CSV import without full
        // normalization against the current options catalog), re-validating
        // already-persisted values against the current whitelist would lock
        // legacy records out of ANY edit, even an unrelated field. Per-step
        // navigation (validateCurrentStep()) still enforces the strict
        // whitelist for freshly-changed selections via step3Rules()/
        // step5Rules()/step6Rules(); the full-record save() safety net only
        // needs to guard against malformed/oversized data for these five
        // fields, not re-litigate already-persisted legacy values.
        foreach (['specialization', 'communityService', 'incomeSource', 'medicalConcern', 'socialEmotionalConcern'] as $field) {
            $rules[$field] = in_array($field, ['medicalConcern', 'socialEmotionalConcern'], true)
                ? 'required|array|min:1'
                : 'array';
            $rules["{$field}.*"] = 'string|max:255';
        }
        // The enum-whitelist relaxation above still must not swallow the
        // marital-status contradiction check — that's a business rule, not
        // taxonomy drift (see spouseIncomeSourceRule() docblock).
        $rules['incomeSource'] = ['array', $this->spouseIncomeSourceRule()];

        // Same legacy-data risk applies to barangay: it's a plain `string`
        // column (not an enum) and both BulkUploadController::upload() and
        // OscaCsvSeeder store it raw/un-normalized (including an 'Unknown'
        // fallback), so an existing record's value may not be in the current
        // barangayList() whitelist. Keep it required, but drop the `in:`
        // constraint here; step1Rules() still enforces the strict whitelist
        // for fresh input via validateCurrentStep().
        $rules['barangay'] = 'required|string|max:255';

        return $rules;
    }

    private function allStepsMessages(): array
    {
        return array_merge($this->step1Messages(), $this->step2Messages());
    }

    /** Latest date of birth that makes someone SeniorCitizen::MINIMUM_AGE today. */
    private function minimumBirthDate(): string
    {
        return now()->subYears(SeniorCitizen::MINIMUM_AGE)->toDateString();
    }

    private function step1Rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:100', NameRules::person()],
            'middleName' => ['nullable', 'string', 'max:100', NameRules::person()],
            'lastName' => ['required', 'string', 'max:100', NameRules::person()],
            'nameExtension' => ['nullable', 'string', 'max:20', NameRules::suffix()],
            'barangay' => 'required|string|in:'.implode(',', SeniorCitizen::barangayList()),
            'officialOscaId' => [
                'nullable', 'string', 'max:50',
                ValidationRule::unique('senior_citizens', 'official_osca_id')->ignore($this->senior?->id),
            ],
            'dateOfBirth' => 'required|date|after_or_equal:1900-01-01|before:today|before_or_equal:'.$this->minimumBirthDate(),
            'gender' => 'required|string|max:255',
            'maritalStatus' => ['required', 'string', ValidationRule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'registrationDate' => 'nullable|date|after_or_equal:1900-01-01|before_or_equal:today|after_or_equal:dateOfBirth',
            'contactNumber' => ['nullable', 'regex:/^\d{7,20}$/'],
            'consentGivenAt' => 'nullable|date|after_or_equal:1900-01-01|before_or_equal:today|required_if:consentMethod,verbal,written,digital|after_or_equal:dateOfBirth',
            'status' => ['required', ValidationRule::in(['active', 'deceased'])],
            'dateOfDeath' => 'nullable|required_if:status,deceased|date|after_or_equal:1900-01-01|before_or_equal:today|after:dateOfBirth',
            'deceasedNote' => 'nullable|string|max:500',
        ];
    }

    private function step1Messages(): array
    {
        return [
            'firstName.regex' => NameRules::PERSON_MESSAGE,
            'middleName.regex' => NameRules::PERSON_MESSAGE,
            'lastName.regex' => NameRules::PERSON_MESSAGE,
            'nameExtension.regex' => NameRules::SUFFIX_MESSAGE,
            'dateOfBirth.after_or_equal' => 'Date of birth must be in the year 1900 or later.',
            'dateOfBirth.before' => 'Date of birth must be in the past.',
            'dateOfBirth.before_or_equal' => 'This senior must be at least '.SeniorCitizen::MINIMUM_AGE.' years old to be registered.',
            'registrationDate.after_or_equal' => 'Registration date must be on or after the date of birth (and in the year 1900 or later).',
            'registrationDate.before_or_equal' => 'Registration date cannot be in the future.',
            'contactNumber.regex' => 'Contact number must contain 7 to 20 digits only.',
            'consentGivenAt.after_or_equal' => 'Consent date must be on or after the date of birth (and in the year 1900 or later).',
            'consentGivenAt.before_or_equal' => 'Consent date cannot be in the future.',
            'dateOfDeath.after_or_equal' => 'Date of death must be in the year 1900 or later.',
            'dateOfDeath.after' => 'Date of death must be after the date of birth.',
            'dateOfDeath.before_or_equal' => 'Date of death cannot be in the future.',
            'dateOfDeath.required_if' => 'Date of death is required when the senior is deceased.',
            'officialOscaId.unique' => self::OFFICIAL_OSCA_ID_UNIQUE_MESSAGE,
        ];
    }

    /** Persist after validation; separated so the database-race path is testable. */
    protected function persistProfile(array $data): void
    {
        if ($this->senior) {
            $this->senior->update($data);

            return;
        }

        $data['osca_id'] = SeniorCitizen::generateOscaId($this->barangay);
        $this->senior = SeniorCitizen::create($data);
    }

    private function isOfficialOscaIdUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $isUniqueViolation = in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, [19, 1062, 2067], true);

        return $isUniqueViolation
            && str_contains(strtolower($exception->getMessage()), 'official_osca_id');
    }

    /** Friendly labels shared by field-level errors and the save-error modal. */
    protected function validationAttributes(): array
    {
        return [
            'firstName' => 'first name',
            'middleName' => 'middle name',
            'lastName' => 'last name',
            'nameExtension' => 'name extension',
            'barangay' => 'barangay',
            'officialOscaId' => 'Official OSCA ID',
            'dateOfBirth' => 'date of birth',
            'registrationDate' => 'registration date',
            'contactNumber' => 'contact number',
            'placeOfBirth' => 'place of birth',
            'maritalStatus' => 'marital status',
            'gender' => 'gender',
            'ethnicOrigin' => 'ethnic origin',
            'bloodType' => 'blood type',
            'dateOfDeath' => 'date of death',
            'deceasedNote' => 'deceased note',
            'numChildren' => 'number of children',
            'numWorkingChildren' => 'number of working children',
            'childFinancialSupport' => 'child financial support',
            'spouseWorking' => 'spouse employment status',
            'householdSize' => 'household size',
            'educationalAttainment' => 'educational attainment',
            'communityService' => 'community service',
            'specialization' => 'specialization',
            'specialization.*' => 'specialization',
            'livingWith' => 'living arrangement',
            'householdCondition' => 'household condition',
            'incomeSource' => 'income source',
            'realAssets' => 'real assets',
            'movableAssets' => 'movable assets',
            'monthlyIncomeRange' => 'monthly income range',
            'problemsNeeds' => 'problems and needs',
            'medicalConcern' => 'medical concern',
            'dentalConcern' => 'dental concern',
            'opticalConcern' => 'optical concern',
            'hearingConcern' => 'hearing concern',
            'socialEmotionalConcern' => 'social and emotional concern',
            'healthcareDifficulty' => 'healthcare access',
            'hasMedicalCheckup' => 'medical checkup status',
            'checkupSchedule' => 'checkup schedule',
            'consentGivenAt' => 'consent date',
            'consentMethod' => 'consent method',
        ];
    }

    /**
     * The set of spouseWorking values consistent with each maritalStatus —
     * single source of truth shared by step2Rules()'s closure and
     * crossFieldViolations(). Null means "no restriction" (blank/unrecognized
     * status — the field's own `required` rule handles emptiness).
     *
     * @return array<int, string>|null
     */
    private function spouseWorkingAllowedValues(): ?array
    {
        return match ($this->maritalStatus) {
            'Single' => ['N/A'],
            'Widowed' => ['Deceased'],
            'Married' => ['Yes', 'No', 'Deceased'],
            'Separated' => ['Yes', 'No', 'N/A'],
            default => null,
        };
    }

    /**
     * "House and Lot" under Real Assets (step5) contradicts Household
     * Condition (step4) indicating the house is owned but the land it sits
     * on isn't — single source of truth shared by step5Rules()'s closure
     * and crossFieldViolations(), same pattern as spouseWorkingAllowedValues().
     */
    private function houseAndLotOwnershipConflict(): bool
    {
        return in_array('House is owned', $this->householdCondition, true)
            && in_array('Land is not owned', $this->householdCondition, true);
    }

    // ── II. Family Composition ────────────────────────────────────────────────
    private function step2Rules(): array
    {
        return [
            'numChildren' => 'required|integer|min:0|max:50',
            'numWorkingChildren' => 'required|integer|min:0|max:50|lte:numChildren',
            'householdSize' => 'required|integer|min:1|max:50',
            'childFinancialSupport' => [
                'required',
                ValidationRule::in(['Yes', 'No', 'Occasional', 'N/A']),
                function ($attribute, $value, $fail) {
                    if ((int) $this->numChildren === 0 && in_array($value, ['Yes', 'Occasional'], true)) {
                        $fail('Financial support from children cannot be "Yes" or "Occasional" when the number of children is 0.');
                    }
                },
            ],
            'spouseWorking' => [
                'required',
                ValidationRule::in(['Yes', 'No', 'Deceased', 'N/A']),
                function ($attribute, $value, $fail) {
                    $allowed = $this->spouseWorkingAllowedValues();
                    if ($allowed !== null && ! in_array($value, $allowed, true)) {
                        $fail(match ($this->maritalStatus) {
                            'Single' => 'Spouse/Partner Working must be "N/A" when marital status is Single.',
                            'Widowed' => 'Spouse/Partner Working must be "Deceased" when marital status is Widowed.',
                            'Married' => 'Spouse/Partner Working cannot be "N/A" when marital status is Married.',
                            'Separated' => 'Spouse/Partner Working cannot be "Deceased" when marital status is Separated.',
                            default => 'Spouse/Partner Working is inconsistent with marital status.',
                        });
                    }
                },
            ],
        ];
    }

    private function step2Messages(): array
    {
        return [
            'numWorkingChildren.lte' => 'Number of working children cannot exceed the number of children.',
        ];
    }

    // ── III. Education / HR Profile ───────────────────────────────────────────
    private function step3Rules(): array
    {
        return [
            'educationalAttainment' => 'required|string|max:255',
            'specialization' => 'array',
            'specialization.*' => ['string', ValidationRule::in(self::specializationOptions())],
            'communityService' => 'array',
            'communityService.*' => ['string', ValidationRule::in(self::communityServiceOptions())],
        ];
    }

    // ── IV. Dependency Profile ────────────────────────────────────────────────
    private function step4Rules(): array
    {
        return [
            'livingWith' => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                $value = (array) $value;
                if (in_array('Spouse', $value, true)
                    && (in_array($this->maritalStatus, ['Single', 'Widowed'], true) || $this->spouseWorking === 'Deceased')) {
                    $fail('Living arrangement cannot include "Spouse" when marital status is Single/Widowed or Spouse/Partner Working is "Deceased".');
                }
                $hasAlone = in_array('Alone', $value, true);
                $sizeIsOne = (int) $this->householdSize === 1;
                if ($hasAlone && ! $sizeIsOne) {
                    $fail('Household size must be 1 when living arrangement is "Alone".');
                }
                if ($sizeIsOne && $value !== [] && ! $hasAlone) {
                    $fail('Living arrangement must be "Alone" when household size is 1.');
                }
            }],
            'livingWith.*' => 'string|max:255',
            'householdCondition' => ['array', function ($attribute, $value, $fail) {
                $value = (array) $value;
                if (in_array('Alone', $this->livingWith, true)
                    && (in_array('Overcrowded in home', $value, true) || in_array('Shared with relatives', $value, true))) {
                    $fail('Household condition cannot include "Overcrowded in home" or "Shared with relatives" while living arrangement is "Alone".');
                }
            }],
            'householdCondition.*' => 'string|max:255',
        ];
    }

    // ── V. Economic Profile ───────────────────────────────────────────────────
    /**
     * "Spouse salary"/"Spouse pension" require an actual spouse to exist —
     * unlike the enum-whitelist relaxation below (which tolerates legacy
     * taxonomy drift), this is a marital-status contradiction, the same
     * class of business rule as spouseWorking/livingWith above, so it must
     * keep enforcing on the full-record save() safety net too, not just
     * per-step navigation.
     */
    private function spouseIncomeSourceRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $value = (array) $value;
            if (in_array($this->maritalStatus, ['Single', 'Widowed'], true)
                && (in_array('Spouse salary', $value, true) || in_array('Spouse pension', $value, true))) {
                $fail('Source of income cannot include "Spouse salary" or "Spouse pension" when marital status is Single or Widowed.');
            }
        };
    }

    private function step5Rules(): array
    {
        return [
            'incomeSource' => ['array', $this->spouseIncomeSourceRule()],
            'incomeSource.*' => ['string', ValidationRule::in(self::incomeSourceOptions())],
            'realAssets' => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                if ($this->houseAndLotOwnershipConflict() && in_array('House and Lot', (array) $value, true)) {
                    $fail('Real assets cannot include "House and Lot" when Household Condition indicates the house is owned but the land is not.');
                }
            }],
            'realAssets.*' => 'string|max:255',
            'movableAssets' => 'required|array|min:1',
            'movableAssets.*' => 'string|max:255',
            'monthlyIncomeRange' => 'required|string|max:255',
            'problemsNeeds' => 'required|array|min:1',
            'problemsNeeds.*' => 'string|max:255',
        ];
    }

    // ── VI. Health Profile ────────────────────────────────────────────────────
    private function step6Rules(): array
    {
        return [
            'medicalConcern' => 'required|array|min:1',
            'medicalConcern.*' => ['string', ValidationRule::in(self::medicalConcernOptions())],
            'socialEmotionalConcern' => 'required|array|min:1',
            'socialEmotionalConcern.*' => ['string', ValidationRule::in(self::socialEmotionalConcernOptions())],
            'dentalConcern' => 'required|array|min:1',
            'dentalConcern.*' => 'string|max:255',
            'opticalConcern' => 'required|array|min:1',
            'opticalConcern.*' => 'string|max:255',
            'hearingConcern' => 'required|array|min:1',
            'hearingConcern.*' => 'string|max:255',
            'healthcareDifficulty' => 'required|array|min:1',
            'healthcareDifficulty.*' => 'string|max:255',
        ];
    }

    public function updatedDateOfBirth(): void
    {
        $this->validateOnly('dateOfBirth', $this->step1Rules(), $this->step1Messages());
    }

    public function updatedRegistrationDate(): void
    {
        $this->validateOnly('registrationDate', $this->step1Rules(), $this->step1Messages());
    }

    public function updatedConsentGivenAt(): void
    {
        $this->validateOnly('consentGivenAt', $this->step1Rules(), $this->step1Messages());
    }

    public function updatedDateOfDeath(): void
    {
        $this->validateOnly('dateOfDeath', $this->step1Rules(), $this->step1Messages());
    }

    private function populateFromModel(SeniorCitizen $s): void
    {
        $this->firstName = $s->first_name;
        $this->middleName = $s->middle_name ?? '';
        $this->lastName = $s->last_name;
        $this->nameExtension = $s->name_extension ?? '';
        $this->barangay = $s->barangay;
        $this->officialOscaId = $s->official_osca_id ?? '';
        $this->dateOfBirth = $s->date_of_birth?->format('Y-m-d') ?? '';
        $this->registrationDate = $s->registration_date?->format('Y-m-d') ?? '';
        $this->contactNumber = $s->contact_number ?? '';
        $this->placeOfBirth = $s->place_of_birth ?? '';
        $this->maritalStatus = $s->marital_status ?? '';
        $this->gender = $s->gender ?? '';
        $this->religion = $s->religion ?? '';
        $this->ethnicOrigin = $s->ethnic_origin ?? '';
        $this->bloodType = $s->blood_type ?? '';
        $this->status = $s->status ?? 'active';
        $this->dateOfDeath = $s->date_of_death?->format('Y-m-d') ?? '';
        $this->deceasedNote = $s->deceased_note ?? '';
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
            'barangay' => $this->barangay, 'officialOscaId' => $this->officialOscaId,
            'dateOfBirth' => $this->dateOfBirth, 'registrationDate' => $this->registrationDate,
            'contactNumber' => $this->contactNumber, 'placeOfBirth' => $this->placeOfBirth,
            'maritalStatus' => $this->maritalStatus, 'gender' => $this->gender,
            'religion' => $this->religion, 'ethnicOrigin' => $this->ethnicOrigin,
            'bloodType' => $this->bloodType,
            'status' => $this->status, 'dateOfDeath' => $this->dateOfDeath, 'deceasedNote' => $this->deceasedNote,
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
