@extends('layouts.app')
@section('page-title', 'Help Centre')
@section('page-subtitle', 'Frequently asked questions and system guide')

@section('content')
<div class="space-y-8 max-w-4xl" x-data="{ open: null }">

    {{-- ── Quick nav ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach ([
            ['#getting-started', 'heroicon-o-play-circle',     'Getting Started'],
            ['#senior-records',  'heroicon-o-users',            'Senior Records'],
            ['#surveys',         'heroicon-o-clipboard-document-list', 'QoL Surveys'],
            ['#risk',            'heroicon-o-shield-check',     'Risk & Assessments'],
            ['#groups',          'heroicon-o-squares-2x2',      'Health Groups'],
            ['#recommendations', 'heroicon-o-light-bulb',       'Recommendations'],
            ['#batch',           'heroicon-o-arrow-path',       'Batch Assessment'],
            ['#faq',             'heroicon-o-question-mark-circle', 'FAQs'],
        ] as [$href, $icon, $label])
        <a href="{{ $href }}"
           class="flex items-center gap-2.5 px-4 py-3 bg-white border border-paper-rule rounded-xl text-sm font-medium text-ink-700 hover:bg-paper-2 hover:border-forest-300 transition-colors">
            <x-dynamic-component :component="$icon" class="w-4 h-4 text-forest-600 flex-shrink-0" />
            {{ $label }}
        </a>
        @endforeach
    </div>

    {{-- ── Getting Started ── --}}
    <section id="getting-started" class="card scroll-mt-6">
        <div class="card-head">
            <div>
                <div class="card-title">Getting Started</div>
                <div class="card-sub">Overview of the OSCA AgeS ense system</div>
            </div>
        </div>
        <div class="card-body prose prose-sm max-w-none text-ink-700 space-y-4">
            <p>
                The OSCA AgeSense system helps barangay health workers manage senior citizen records,
                track Quality of Life (QoL) surveys, and identify seniors who need care or intervention.
            </p>
            <p>The system works in three steps:</p>
            <ol class="list-decimal list-inside space-y-1.5 text-sm">
                <li><strong>Register</strong> a senior citizen and fill in their profile.</li>
                <li><strong>Conduct</strong> a QoL survey to capture their current health and living situation.</li>
                <li><strong>Run the assessment</strong> to automatically assign a risk level and generate care recommendations.</li>
            </ol>
            <div class="bg-forest-50 border border-forest-200 rounded-xl px-4 py-3 text-sm text-forest-800">
                <strong>Tip:</strong> Start from <strong>Senior Records → New Profile</strong> to register a senior, then go to their profile page to add a QoL survey.
            </div>
        </div>
    </section>

    {{-- ── Senior Records ── --}}
    <section id="senior-records" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Senior Records</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I add a new senior citizen?</p>
                <p>Go to <strong>Senior Records → New Profile</strong> in the sidebar. Fill in all required fields (name, OSCA ID, date of birth, barangay) and submit. The senior's profile page will open automatically.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I edit a senior's information?</p>
                <p>Open the senior's profile and click the <strong>Edit</strong> button in the top-right corner.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What does archiving a senior do?</p>
                <p>Archiving hides a senior from active lists and reports without permanently deleting their records. Archived seniors can be restored at any time from the <strong>Archives</strong> section. Their past assessment results are preserved but excluded from group analysis.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I restore an archived senior?</p>
                <p>Go to <strong>Archives</strong> in the sidebar, find the senior, and click <strong>Restore</strong>.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What is the OSCA ID?</p>
                <p>The OSCA ID is the official identification number assigned by your local Office for Senior Citizens Affairs. It must be unique for each senior in the system.</p>
            </div>
        </div>
    </section>

    {{-- ── QoL Surveys ── --}}
    <section id="surveys" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Quality of Life (QoL) Surveys</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-1">What is a QoL survey?</p>
                <p>A Quality of Life survey captures a senior's current health, social, financial, and environmental situation. It is the main input used to assess their risk level and generate recommendations.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I conduct a QoL survey?</p>
                <ol class="list-decimal list-inside space-y-1">
                    <li>Open the senior's profile page.</li>
                    <li>Click <strong>+ New QoL Survey</strong>.</li>
                    <li>Fill in each section — you can navigate between sections using the Next button.</li>
                    <li>On the last section, click <strong>Submit &amp; Run Assessment</strong>.</li>
                </ol>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What are the survey sections?</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><strong>Physical Health</strong> — mobility, chronic illness, sensory concerns</li>
                    <li><strong>Psychological</strong> — emotional wellbeing, memory, mood</li>
                    <li><strong>Social</strong> — relationships, community participation</li>
                    <li><strong>Financial</strong> — income sources, economic stability</li>
                    <li><strong>Environment</strong> — housing, access to services</li>
                    <li><strong>Spirituality &amp; Independence</strong> — sense of purpose, daily activity levels</li>
                </ul>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">Can I submit multiple surveys for one senior?</p>
                <p>Yes. Each survey is a snapshot in time. The most recent survey is always used for the senior's current risk assessment. You can view all past surveys from the senior's profile.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">Can I delete a survey?</p>
                <p>Yes. Open the senior's profile, go to the QoL Survey History section, and click <strong>Delete</strong> next to the survey. This also removes the associated assessment results. This action cannot be undone.</p>
            </div>
        </div>
    </section>

    {{-- ── Risk & Assessments ── --}}
    <section id="risk" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Risk Levels &amp; Assessments</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-2">What do the risk levels mean?</p>
                <div class="space-y-2">
                    @foreach ([
                        ['HIGH',     'badge-high',     'Significant concerns present across multiple domains. Priority action or intervention required. Seniors with scores ≥ 0.70 are flagged as urgent-priority.'],
                        ['MODERATE', 'badge-moderate', 'Some risk factors present. Planned monitoring and targeted support recommended.'],
                        ['LOW',      'badge-low',      'Senior is generally well. Continue routine check-ins and maintenance programs.'],
                    ] as [$level, $badge, $desc])
                    <div class="flex items-start gap-3">
                        <span class="badge {{ $badge }} flex-shrink-0 mt-0.5">{{ $level }}</span>
                        <span>{{ $desc }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What are the three risk areas?</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><strong>Physical Capacity</strong> — intrinsic health and bodily function</li>
                    <li><strong>Environment</strong> — living conditions and access to support</li>
                    <li><strong>Daily Functioning</strong> — ability to carry out day-to-day activities independently</li>
                </ul>
                <p class="mt-2">Each area gets its own risk score. The <strong>Overall Risk</strong> combines all three.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I run or re-run an assessment?</p>
                <p>Open the senior's profile and click <strong>Re-run Assessment</strong>. The system will process the latest survey and update the risk scores and recommendations. This takes a few seconds.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What does "Wellbeing Score" mean?</p>
                <p>The Wellbeing Score (shown as a percentage out of 100) summarises the senior's overall positive health status. A higher score means better wellbeing. It is the inverse of overall risk.</p>
            </div>
        </div>
    </section>

    {{-- ── Health Groups ── --}}
    <section id="groups" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Health Groups</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-1">What are health groups?</p>
                <p>Health groups automatically sort seniors into three categories based on patterns in their QoL survey results. Seniors with similar health profiles are placed in the same group. This helps identify which seniors share similar needs so resources can be planned more efficiently.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-2">What does each group mean?</p>
                <div class="space-y-2">
                    @foreach ([
                        ['Group 1', 'text-emerald-700 bg-emerald-50 border-emerald-200', 'High Functioning',  'Seniors who are relatively independent with low risk across all areas.'],
                        ['Group 2', 'text-amber-700 bg-amber-50 border-amber-200',       'Moderate / Mixed',  'Seniors with some areas of concern. Targeted support is recommended.'],
                        ['Group 3', 'text-rose-700 bg-rose-50 border-rose-200',          'Low Functioning',   'Seniors with multiple high-risk areas who need the most support.'],
                    ] as [$grp, $cls, $name, $desc])
                    <div class="flex items-start gap-3">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-lg border flex-shrink-0 mt-0.5 {{ $cls }}">{{ $grp }}</span>
                        <span><strong>{{ $name }}</strong> — {{ $desc }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">Where can I see the health group breakdown?</p>
                <p>Go to <strong>Health Groups</strong> in the sidebar. You can filter by barangay, see per-group risk distributions, and export the data as a CSV file.</p>
            </div>
        </div>
    </section>

    {{-- ── Recommendations ── --}}
    <section id="recommendations" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Recommendations</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-1">Where do recommendations come from?</p>
                <p>Recommendations are automatically generated each time an assessment is run. They are based on the senior's specific risk scores across physical, environmental, and functional areas.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What do the urgency levels mean?</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><strong>Immediate</strong> — act as soon as possible</li>
                    <li><strong>Urgent</strong> — schedule within the week</li>
                    <li><strong>Planned</strong> — include in the next regular visit</li>
                </ul>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I mark a recommendation as done?</p>
                <p>Go to <strong>Recommendations</strong> in the sidebar or open the senior's profile. Find the recommendation and update its status. Completed recommendations are kept for record purposes.</p>
            </div>
        </div>
    </section>

    {{-- ── Batch Assessment ── --}}
    <section id="batch" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Batch Assessment</div>
        </div>
        <div class="card-body space-y-5 text-sm text-ink-700">
            <div>
                <p class="font-semibold text-ink-900 mb-1">What is batch assessment?</p>
                <p>Batch assessment runs the health assessment for all seniors who have a QoL survey but have not been assessed yet (or need re-assessment). Instead of running them one by one, batch mode processes everyone at once.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How do I run a batch assessment?</p>
                <ol class="list-decimal list-inside space-y-1">
                    <li>Go to <strong>Assessment Tools → Batch Analysis</strong> in the sidebar.</li>
                    <li>Review the list of eligible seniors.</li>
                    <li>Click <strong>Run Full Batch</strong> and confirm.</li>
                    <li>Wait for the page to refresh — do not close the tab while it is running.</li>
                </ol>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">How long does it take?</p>
                <p>Typically 1–3 minutes depending on the number of seniors. A progress indicator is shown while it runs.</p>
            </div>
            <div>
                <p class="font-semibold text-ink-900 mb-1">What if the analysis service is offline?</p>
                <p>Go to <strong>Assessment Tools → Service Status</strong>. If the service shows as offline, click <strong>Start Services</strong>. If the problem persists, contact your system administrator.</p>
            </div>
        </div>
    </section>

    {{-- ── FAQs ── --}}
    <section id="faq" class="card scroll-mt-6">
        <div class="card-head">
            <div class="card-title">Frequently Asked Questions</div>
        </div>
        <div class="card-body divide-y divide-paper-rule">
            @foreach ([
                [
                    "Q: A senior's name shows as \"—\" in the Health Groups table.",
                    "This means the senior has been archived. Archived seniors are excluded from group analysis. If you need them included, restore them from the Archives section.",
                ],
                [
                    "Q: The risk score did not change after I updated the survey.",
                    "You need to re-run the assessment after editing a survey. Open the senior's profile and click Re-run Assessment.",
                ],
                [
                    "Q: I cannot find a senior in the records list.",
                    "They may have been archived. Check the Archives section in the sidebar. You can restore them from there.",
                ],
                [
                    "Q: The assessment service shows as \"Offline\".",
                    "Go to Assessment Tools → Service Status and click Start Services. If it does not come online, ask your administrator to restart the analysis services on the server.",
                ],
                [
                    "Q: Can two seniors share the same OSCA ID?",
                    "No. OSCA IDs must be unique. The system will reject a duplicate OSCA ID when you try to save the profile.",
                ],
                [
                    "Q: How often should QoL surveys be conducted?",
                    "It is recommended to conduct a QoL survey at least once every six months, or whenever there is a significant change in a senior's health or living situation.",
                ],
                [
                    "Q: Can I export data?",
                    "Yes. The Health Groups report has an Export CSV button. Individual senior profiles can be exported as a PDF using the Export PDF button on their profile page.",
                ],
                [
                    "Q: Who can access the system?",
                    "Access requires a login. User accounts are managed by the system administrator. Contact your administrator to add or remove users.",
                ],
            ] as [$question, $answer])
            <div x-data="{ open: false }" class="py-3">
                <button @click="open = !open"
                        class="w-full flex items-center justify-between text-left gap-4 text-sm font-semibold text-ink-900 hover:text-forest-700 transition-colors">
                    <span>{{ $question }}</span>
                    <x-heroicon-o-chevron-down class="w-4 h-4 flex-shrink-0 transition-transform duration-200"
                                               ::class="open ? 'rotate-180' : ''" />
                </button>
                <div x-show="open" x-transition class="mt-2 text-sm text-ink-600 leading-relaxed pr-6">
                    {{ $answer }}
                </div>
            </div>
            @endforeach
        </div>
    </section>

</div>
@endsection
