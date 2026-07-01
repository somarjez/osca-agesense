@extends('layouts.app')
@section('page-title', 'Help Centre')
@section('page-subtitle', 'Frequently asked questions and system guide')

@section('content')
<div class="space-y-6" x-data="helpCentre()">

    <x-page-header title="Help Centre" subtitle="Frequently asked questions and system guide" />

    {{-- ── Search ── --}}
    <div class="relative">
        <x-heroicon-o-magnifying-glass class="w-4 h-4 text-ink-400 dark:text-[#6b7570] absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none" />
        <input type="text"
               x-model.debounce.150ms="search"
               @keydown.escape="search = ''; activeTopic = 'all'"
               placeholder="Search for help or type a question…"
               autocomplete="off"
               class="w-full pl-11 pr-10 py-3 text-[13.5px] bg-white dark:bg-[#1a201d] border border-paper-rule dark:border-[#2b3530] rounded-2xl focus:border-forest-400 dark:focus:border-forest-600 focus:ring-2 focus:ring-forest-400/20 outline-none transition-all placeholder:text-ink-300 dark:placeholder:text-[#4a5550] text-ink-900 dark:text-[#e4e1d8] shadow-sm">
        <button x-show="search" x-cloak
                @click="search = ''"
                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-ink-300 hover:text-ink-600 dark:hover:text-[#c8c4bc] transition-colors p-1">
            <x-heroicon-o-x-mark class="w-4 h-4" />
        </button>
    </div>

    {{-- ── Topic pills ── --}}
    <div class="flex flex-wrap gap-2">
        @foreach ([
            ['all',             'All Topics'],
            ['getting-started', 'Getting Started'],
            ['senior-records',  'Senior Records'],
            ['surveys',         'QoL Surveys'],
            ['risk',            'Risk & Assessments'],
            ['groups',          'Profile Groups'],
            ['recommendations', 'Recommendations'],
            ['batch',           'Batch Assessment'],
            ['general',         'FAQs'],
        ] as [$key, $label])
        <button type="button"
                @click="activeTopic = '{{ $key }}'; search = ''"
                :class="activeTopic === '{{ $key }}'
                    ? 'bg-navy-800 dark:bg-navy-700 text-paper border-navy-800 dark:border-navy-700'
                    : 'bg-white dark:bg-[#1a201d] text-ink-600 dark:text-[#8a9087] border-paper-rule dark:border-[#2b3530] hover:border-ink-300 dark:hover:border-[#4a5550]'"
                class="px-3.5 py-1.5 rounded-full text-[12px] font-medium border transition-colors duration-100">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- ── Result count ── --}}
    <div x-show="search.trim() || activeTopic !== 'all'" x-cloak
         class="flex items-center gap-2 text-[12px] text-ink-400 dark:text-[#6b7570]">
        <span><span x-text="totalFiltered"></span> <span x-text="totalFiltered === 1 ? 'result' : 'results'"></span><template x-if="search.trim()"> for &ldquo;<span class="text-ink-700 dark:text-[#c8c4bc]" x-text="search.trim()"></span>&rdquo;</template></span>
        <button @click="search = ''; activeTopic = 'all'"
                class="text-forest-600 dark:text-forest-400 hover:underline">Clear</button>
    </div>

    {{-- ── Sections ── --}}
    <template x-for="sec in visibleSections" :key="sec.key">
        <section class="card scroll-mt-6">
            <div class="card-head">
                <div>
                    <div class="card-title" x-text="sec.title"></div>
                    <div class="card-sub" x-show="sec.sub" x-text="sec.sub"></div>
                </div>
            </div>
            <div class="card-body divide-y divide-paper-rule dark:divide-[#2b3530] -mt-1">
                <template x-for="item in itemsForSection(sec.key)" :key="item.q">
                    <div class="pt-4 first:pt-0 pb-1 last:pb-0 space-y-1.5 text-sm text-ink-700 dark:text-[#c8c4bc]">
                        <p class="font-semibold text-ink-900 dark:text-[#e4e1d8]" x-html="hl(item.q)"></p>
                        <p class="leading-relaxed" x-html="hl(item.a)"></p>
                    </div>
                </template>
            </div>
        </section>
    </template>

    {{-- ── Empty state ── --}}
    <template x-if="visibleSections.length === 0">
        <div class="card">
            <div class="card-body py-16 text-center">
                <x-heroicon-o-magnifying-glass class="w-8 h-8 text-ink-200 dark:text-[#2b3530] mx-auto mb-3" />
                <p class="text-[13.5px] font-semibold text-ink-500 dark:text-[#6b7570]">
                    No results for &ldquo;<span x-text="search"></span>&rdquo;
                </p>
                <p class="text-[12.5px] text-ink-400 dark:text-[#4a5550] mt-1">
                    Try a shorter term, or
                    <button @click="search = ''; activeTopic = 'all'"
                            class="text-forest-600 dark:text-forest-400 hover:underline">browse all topics</button>.
                </p>
            </div>
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
function helpCentre() {
    const sections = [
        { key: 'getting-started', title: 'Getting Started',               sub: 'Overview of the OSCA AgeSense system' },
        { key: 'senior-records',  title: 'Senior Records',                sub: null },
        { key: 'surveys',         title: 'Quality of Life (QoL) Surveys', sub: null },
        { key: 'risk',            title: 'Risk Levels & Assessments',      sub: null },
        { key: 'groups',          title: 'Profile Groups',                 sub: null },
        { key: 'recommendations', title: 'Recommendations',                sub: null },
        { key: 'batch',           title: 'Batch Assessment',               sub: null },
        { key: 'general',         title: 'Frequently Asked Questions',     sub: null },
    ];

    const items = [
        // Getting Started
        { topic: 'getting-started', q: 'What is AgeSense?', a: 'AgeSense is a decision-support system for OSCA Pagsanjan staff. It helps manage senior citizen records, track Quality of Life surveys, identify seniors at risk, and generate care recommendations — all based on the WHO Healthy Ageing framework. Results are decision-support indicators, not clinical diagnoses.' },
        { topic: 'getting-started', q: 'What is the standard workflow?', a: 'Three steps: (1) Register a senior and fill in their profile. (2) Conduct a QoL survey to capture their current health and living situation. (3) Run the assessment to get a risk level, profile group assignment, and care recommendations. Repeat the survey at least every six months or after a significant health change.' },
        { topic: 'getting-started', q: 'Who can use the system?', a: 'Three roles exist. Administrators manage everything — profiles, assessments, user accounts, and exports. Encoders can capture and edit senior profiles and surveys, and run assessments. Viewers can read dashboards and reports but cannot make changes. Accounts are managed by your system administrator.' },
        { topic: 'getting-started', q: 'How do I switch to dark mode?', a: 'Click the moon icon in the top-right corner of the screen. The system remembers your preference per device.' },
        { topic: 'getting-started', q: 'Where do I start if this is my first time?', a: 'Go to Senior Records → New Profile to register your first senior. Once their profile is saved, open it and click New QoL Survey. After submitting the survey, click Re-run Assessment to generate risk scores and recommendations.' },

        // Senior Records
        { topic: 'senior-records', q: 'How do I add a new senior citizen?', a: 'Go to Senior Records → New Profile in the sidebar. Fill in all required fields — name, OSCA ID, date of birth, barangay — and submit. The senior\'s profile page opens automatically.' },
        { topic: 'senior-records', q: 'How do I edit a senior\'s information?', a: 'Open the senior\'s profile and click Edit in the top-right action bar. Make your changes and save. If you edit information that affects the risk score, re-run the assessment afterwards so the scores stay current.' },
        { topic: 'senior-records', q: 'What does archiving a senior do?', a: 'Archiving hides a senior from active lists and reports without permanently deleting their data. Their past assessment results are preserved but excluded from group analysis. Archived seniors can be restored at any time from the Archives section.' },
        { topic: 'senior-records', q: 'How do I restore an archived senior?', a: 'Go to Archives in the sidebar, find the senior, and click Restore. Their profile returns to the active records list with all historical data intact.' },
        { topic: 'senior-records', q: 'What is the OSCA ID?', a: 'The OSCA ID is the official identification number assigned by your local Office for Senior Citizens Affairs. It must be unique across the system — the system will reject a duplicate OSCA ID when saving a profile.' },
        { topic: 'senior-records', q: 'How do I export a senior\'s profile as PDF?', a: 'Open the senior\'s profile and click Export PDF in the top-right action bar. The PDF includes identifying information, health profile, risk assessment results, and recommendations — formatted as an official OSCA document with a control number and signatory line.' },
        { topic: 'senior-records', q: 'How does bulk upload work?', a: 'Go to Senior Records and click Bulk Upload. Download the CSV template, fill in the required columns (first_name, last_name, barangay, dob, gender), then upload the file. Rows with missing required fields are skipped. The system automatically runs assessments for all imported seniors within a minute.' },

        // QoL Surveys
        { topic: 'surveys', q: 'What is a QoL survey?', a: 'A Quality of Life survey captures a senior\'s current health, social, financial, and environmental situation across six domains. It is the main input for the risk assessment — without a completed survey, no risk score can be generated.' },
        { topic: 'surveys', q: 'How do I conduct a QoL survey?', a: 'Open the senior\'s profile and click New QoL Survey. Fill in each section and use Next to move forward. On the last section, click Submit & Run Assessment. Risk scores appear on the profile within a few seconds.' },
        { topic: 'surveys', q: 'What are the QoL survey sections?', a: 'The survey covers six domains: Physical Health (mobility, chronic illness, sensory concerns), Psychological (emotional wellbeing, memory, mood), Social (relationships, community participation), Financial (income sources, economic stability), Environment (housing, access to services), and Spirituality & Independence (sense of purpose, daily activity levels).' },
        { topic: 'surveys', q: 'Can I submit multiple surveys for one senior?', a: 'Yes. Each survey is a timestamped snapshot. The most recent survey always drives the current risk assessment. All past surveys remain visible in the QoL Survey History section of the senior\'s profile.' },
        { topic: 'surveys', q: 'Can I delete a QoL survey?', a: 'Yes. Open the senior\'s profile, go to QoL Survey History, and click Delete next to the survey. This permanently removes the survey and its associated assessment results. This action cannot be undone.' },
        { topic: 'surveys', q: 'What is the Consent field in the profile form?', a: 'Records whether the senior has given informed consent for their data to be used in the system. You can record the date and method (verbal or written). This is required for data governance and is printed on exported PDF records.' },

        // Risk & Assessments
        { topic: 'risk', q: 'What do the risk levels mean?', a: 'HIGH means significant concerns across multiple domains — priority action required. Seniors with a composite score of 70% or above are additionally flagged urgent-priority. MODERATE means some risk factors are present — planned monitoring and targeted support are recommended. LOW means the senior is generally well — routine check-ins are appropriate.' },
        { topic: 'risk', q: 'What are the three risk domains?', a: 'Physical Capacity covers intrinsic health and bodily function. Environment covers living conditions and access to support and services. Daily Functioning covers the ability to carry out day-to-day activities independently. Each domain gets its own risk score, and the Overall Risk combines all three.' },
        { topic: 'risk', q: 'How do I run or re-run an assessment?', a: 'Open the senior\'s profile and click Re-run Assessment. The system processes the latest QoL survey and updates risk scores, profile group assignment, and recommendations. This takes a few seconds and the page reloads automatically when complete.' },
        { topic: 'risk', q: 'What does the Wellbeing Score mean?', a: 'The Wellbeing Score (0–100) summarises the senior\'s overall positive health status — roughly the inverse of the composite risk score. A score of 67 means reasonable overall wellbeing across the assessed domains. Higher is better.' },
        { topic: 'risk', q: 'What does "Results may be outdated" mean?', a: 'This banner appears when the senior\'s profile or survey was modified after the last assessment ran. The displayed scores may no longer reflect current data. Click Re-run Assessment to get accurate, up-to-date results.' },
        { topic: 'risk', q: 'What is the urgent-priority threshold?', a: 'Seniors with a composite risk score of 70% or higher are automatically flagged urgent-priority. This appears as a pulsing orange badge next to their HIGH risk label on the Senior Records list and their profile, and they appear at the top of the Risk Reports at-risk table.' },

        // Profile Groups
        { topic: 'groups', q: 'What are profile groups?', a: 'Profile groups automatically sort seniors into four categories based on patterns in their QoL survey results. Seniors with similar health profiles are placed together. This helps OSCA staff identify which seniors share similar needs so resources can be planned more efficiently.' },
        { topic: 'groups', q: 'What does each profile group mean?', a: 'Group 1 (High Functioning) — relatively independent, low risk across all domains. Group 2 (Stable / Moderate) — some areas of concern; targeted support recommended. Group 3 (Env / Financial Vulnerable) — good intrinsic capacity but environmental and financial stressors are significant. Group 4 (Multi-Domain Priority) — vulnerability across multiple domains; requires coordinated intervention.' },
        { topic: 'groups', q: 'Where can I see the profile group breakdown?', a: 'Go to Profile Groups in the sidebar. You can see per-group risk averages, WHO domain charts, barangay distribution, model insights, and snapshot history. Use Export CSV to download the data for reporting.' },

        // Recommendations
        { topic: 'recommendations', q: 'Where do recommendations come from?', a: 'Recommendations are automatically generated each time an assessment runs, based on the senior\'s specific risk scores. They are decision-support outputs — not clinical prescriptions — and should be used alongside professional assessment and OSCA case knowledge.' },
        { topic: 'recommendations', q: 'What do the urgency levels mean?', a: 'Immediate — act as soon as possible, typically for urgent-priority seniors. Urgent — schedule action within the week. Planned — include in the next regular visit or care review.' },
        { topic: 'recommendations', q: 'How do I mark a recommendation as done?', a: 'Go to Recommendations in the sidebar or open the senior\'s profile. Find the recommendation and use the status dropdown to update it to Completed. Completed recommendations are kept for record purposes.' },
        { topic: 'recommendations', q: 'Can I see all recommendations for a senior in one place?', a: 'Yes. Open the senior\'s profile and click the View all button in the Recommendations card header. This opens the full recommendations page for that senior, where you can update statuses and see every category at once.' },

        // Batch Assessment
        { topic: 'batch', q: 'What is batch assessment?', a: 'Batch assessment runs the health assessment for all eligible seniors at once — those who have a QoL survey but have not yet been assessed, or whose results are stale. Instead of running them one by one, batch mode processes everyone in a single operation.' },
        { topic: 'batch', q: 'How do I run a batch assessment?', a: 'Go to Assessment Tools → Batch Analysis in the sidebar. Review the list of eligible seniors. Click Run Full Batch and confirm. Keep the tab open while it runs — a progress indicator shows the current status. The page refreshes automatically when complete.' },
        { topic: 'batch', q: 'How long does a batch assessment take?', a: 'Typically 1–3 minutes depending on the number of seniors with pending assessments. A progress bar is shown throughout. Do not close the tab while it is running.' },
        { topic: 'batch', q: 'What if the analysis service is offline?', a: 'Go to Assessment Tools → Service Status. If the service shows as offline, click Start Services and wait about 60 seconds for the model to load. If it does not come online, ask your administrator to check the server.' },

        // General / FAQs
        { topic: 'general', q: 'A senior\'s name shows as "—" in the Profile Groups table.', a: 'This means the senior has been archived. Archived seniors are excluded from group analysis. If you need them included, restore them from the Archives section in the sidebar.' },
        { topic: 'general', q: 'The risk score did not change after I updated the survey.', a: 'You need to re-run the assessment after editing a survey. Open the senior\'s profile and click Re-run Assessment. Scores update within a few seconds.' },
        { topic: 'general', q: 'I cannot find a senior in the records list.', a: 'They may have been archived. Check the Archives section in the sidebar. If they appear there, click Restore to bring them back to the active list.' },
        { topic: 'general', q: 'The assessment service shows as "Offline".', a: 'Go to Assessment Tools → Service Status and click Start Services. Wait about 60 seconds for the model to load. If it does not come online, ask your administrator to restart the analysis services on the server.' },
        { topic: 'general', q: 'Can two seniors share the same OSCA ID?', a: 'No. OSCA IDs must be unique across the entire system. The system will reject a duplicate OSCA ID when saving a profile and display an error message.' },
        { topic: 'general', q: 'How often should QoL surveys be conducted?', a: 'At least once every six months, or whenever there is a significant change in a senior\'s health or living situation — such as a hospitalisation, a change in living arrangement, or a major illness.' },
        { topic: 'general', q: 'Can I export data?', a: 'Yes. Individual senior profiles can be exported as PDF from their profile page. The Profile Groups and Risk Reports pages each have an Export CSV button. The Senior Records list also supports bulk CSV export.' },
        { topic: 'general', q: 'Who can access the system?', a: 'Access requires a login. User accounts and role assignments are managed by the system administrator. Contact your administrator to add or remove users or to change permissions.' },
    ];

    return {
        search: '',
        activeTopic: 'all',
        sections,
        items,

        get filtered() {
            const q = this.search.toLowerCase().trim();
            return this.items.filter(item => {
                const topicMatch = this.activeTopic === 'all' || item.topic === this.activeTopic;
                const textMatch  = !q ||
                    item.q.toLowerCase().includes(q) ||
                    item.a.toLowerCase().includes(q);
                return topicMatch && textMatch;
            });
        },

        get totalFiltered() { return this.filtered.length; },

        get visibleSections() {
            const f = this.filtered;
            return this.sections.filter(sec => f.some(i => i.topic === sec.key));
        },

        itemsForSection(key) {
            return this.filtered.filter(i => i.topic === key);
        },

        esc(t) {
            return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        },

        hl(text) {
            const e = this.esc(text);
            const q = this.search.trim();
            if (!q) return e;
            const s = q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
            return e.replace(new RegExp('('+s+')','gi'),
                '<mark style="background:rgba(193,154,59,0.18);color:#7a5a00;border-radius:3px;padding:0 2px;">$1</mark>');
        },
    };
}
</script>
@endpush
