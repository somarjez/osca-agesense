<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Senior Citizen Profile Report</title>
<style>
@page { margin: 1in; }
/* Explicit element list, not a bare `*` universal selector — Dompdf's fixed-position
   page-footer rendering silently drops the footer on every page when a universal
   selector is present anywhere in the stylesheet (confirmed via isolated render test). */
body, div, p, table, td, th, tr, thead, tbody, span, hr, ul, li {
    margin: 0; padding: 0; box-sizing: border-box;
}
body {
    font-family: Calibri, 'DejaVu Sans', Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.15;
    color: #333333;
    background: #ffffff;
}
p { margin: 0 0 6pt 0; }

/* ---------- Header / letterhead ---------- */
.gov-header { text-align: center; padding-bottom: 6pt; }
.gov-header .republic { font-size: 11pt; color: #333333; }
.gov-header .municipality { font-size: 11pt; color: #333333; }
.gov-header .office { font-size: 12pt; font-weight: bold; color: #333333; margin-top: 2pt; }
.gov-header .report-title { font-size: 18pt; font-weight: bold; color: #0D47A1; margin-top: 10pt; letter-spacing: 0.02em; }
.gov-header .report-subtitle { font-size: 12pt; color: #333333; margin-top: 2pt; }
.hr-rule { border: none; border-top: 1pt solid #0D47A1; margin: 8pt 0 10pt 0; }

/* ---------- Generic bordered data tables ---------- */
table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
table.data-table td, table.data-table th { border: 0.5pt solid #333333; padding: 4pt 6pt; font-size: 10pt; vertical-align: top; }
table.data-table td.lbl { width: 22%; font-weight: bold; background: #F2F2F2; }
table.data-table td.val { width: 28%; font-weight: normal; }

/* Column-header tables (Health Profile, Risk Summary, Recommendations) */
table.col-table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
table.col-table th {
    background: #0D47A1; color: #ffffff; font-size: 10pt; font-weight: bold;
    text-align: left; padding: 5pt 6pt; border: 0.5pt solid #0D47A1;
}
table.col-table td { border: 0.5pt solid #333333; padding: 5pt 6pt; font-size: 10pt; vertical-align: top; }
table.col-table tbody tr:nth-child(even) { background: #F2F2F2; }
table.col-table tr { page-break-inside: avoid; }

/* ---------- Metadata table (below title) ---------- */
table.meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
table.meta-table td { border: 0.5pt solid #333333; padding: 4pt 6pt; font-size: 10pt; }
table.meta-table td.lbl { width: 18%; font-weight: bold; background: #F2F2F2; }
table.meta-table td.val { width: 32%; }

/* ---------- Executive summary panel ---------- */
.exec-summary { border: 1pt solid #0D47A1; padding: 8pt 10pt; margin-bottom: 14pt; page-break-inside: avoid; }
.exec-summary .exec-title { font-size: 11pt; font-weight: bold; color: #0D47A1; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6pt; }
table.exec-table { width: 100%; border-collapse: collapse; }
table.exec-table td { padding: 3pt 6pt; font-size: 10.5pt; vertical-align: top; }
table.exec-table td.lbl { font-weight: bold; width: 20%; color: #333333; }
table.exec-table td.val { width: 30%; }

/* ---------- Section headers ---------- */
.section-head {
    background: #0D47A1; color: #ffffff; font-size: 13pt; font-weight: bold;
    padding: 5pt 8pt; margin-top: 16pt; margin-bottom: 8pt;
    page-break-after: avoid;
}
.section-body { page-break-inside: auto; }
.section-empty { font-size: 10pt; color: #333333; font-style: italic; margin-bottom: 10pt; }

/* ---------- Badges (risk level / priority / status) ---------- */
.badge {
    display: inline-block; padding: 2pt 7pt; font-size: 9pt; font-weight: bold;
    border: 0.75pt solid #333333; text-align: center; white-space: nowrap;
}
.badge-high      { background: #0D47A1; color: #ffffff; border-color: #0D47A1; }
.badge-moderate  { background: #333333; color: #ffffff; border-color: #333333; }
.badge-low       { background: #ffffff; color: #333333; border-color: #333333; }
.badge-none      { background: #F2F2F2; color: #333333; border-color: #F2F2F2; }

.text-center { text-align: center; }
.text-right { text-align: right; }
.small-ref { font-size: 8.5pt; color: #333333; }
.mono { font-family: 'DejaVu Sans Mono', monospace; }

/* ---------- Signatures ---------- */
.signatures { width: 100%; border-collapse: collapse; margin-top: 30pt; page-break-inside: avoid; }
.signatures td { width: 33.33%; padding: 0 10pt; text-align: center; vertical-align: bottom; font-size: 10pt; }
.sig-line { border-top: 0.75pt solid #333333; margin-top: 40pt; padding-top: 4pt; }
.sig-role { font-size: 8.5pt; color: #333333; margin-top: 2pt; }

/* Note: the repeating "Page X of Y" footer is NOT done in CSS. Dompdf's
   counter(pages) (total page count) is unreliable in this build — it renders
   as a literal 0 — so the footer is drawn per-page via the canvas API
   (Dompdf's {PAGE_NUM}/{PAGE_COUNT} page_text tokens) in
   SeniorCitizenController::export(), after ->render() and before ->download(). */
</style>
</head>
<body>

@php
    $ml   = $senior->latestMlResult;
    $recs = $ml?->recommendations ?? collect();
    $riskLevel = strtoupper($ml?->overall_risk_level ?? 'NONE');

    if (! function_exists('osca_pdf_badge_class')) {
        function osca_pdf_badge_class(?string $level): string
        {
            return match (strtoupper($level ?? '')) {
                'HIGH' => 'badge-high',
                'MODERATE' => 'badge-moderate',
                'LOW' => 'badge-low',
                default => 'badge-none',
            };
        }
    }
    if (! function_exists('osca_pdf_list')) {
        function osca_pdf_list($items): string
        {
            $items = array_filter((array) ($items ?? []));

            return $items ? e(implode(', ', $items)) : '—';
        }
    }
@endphp

{{-- Header / letterhead --}}
<div class="gov-header">
    <div class="republic">Republic of the Philippines</div>
    <div class="municipality">Municipality of Pagsanjan</div>
    <div class="office">Office for Senior Citizens Affairs</div>
    <div class="report-title">SENIOR CITIZEN PROFILE REPORT</div>
</div>
<hr class="hr-rule">

{{-- Metadata table --}}
<table class="meta-table">
    <tr>
        <td class="lbl">OSCA ID</td>
        <td class="val">{{ $senior->official_osca_id_display }}</td>
        <td class="lbl">Generated Date</td>
        <td class="val">{{ now()->format('F j, Y') }}</td>
    </tr>
    <tr>
        <td class="lbl">System ID</td>
        <td class="val">{{ $senior->osca_id }}</td>
        <td class="lbl">Classification</td>
        <td class="val">Confidential — Official OSCA Use Only</td>
    </tr>
</table>

{{-- Executive Summary --}}
<div class="exec-summary">
    <div class="exec-title">Executive Summary</div>
    <table class="exec-table">
        <tr>
            <td class="lbl">Senior Name</td>
            <td class="val">{{ $senior->full_name }}</td>
            <td class="lbl">Age</td>
            <td class="val">{{ $senior->age }}</td>
        </tr>
        <tr>
            <td class="lbl">Sex</td>
            <td class="val">{{ $senior->gender ?? '—' }}</td>
            <td class="lbl">Barangay</td>
            <td class="val">{{ $senior->barangay ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Overall Risk Level</td>
            <td class="val">
                <span class="badge {{ osca_pdf_badge_class($riskLevel) }}">{{ $riskLevel === 'NONE' ? 'NO DATA' : $riskLevel }}</span>
                @if($ml?->critical_flag)<span class="badge badge-high" style="margin-left:4pt;">CRITICAL PRIORITY</span>@endif
            </td>
            <td class="lbl">Composite Risk Score</td>
            <td class="val">{{ $ml ? number_format($ml->composite_risk ?? 0, 3) : '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Profile Group</td>
            <td class="val" colspan="3">{{ $ml?->cluster_name ? 'Group '.$ml->cluster_named_id.' — '.$ml->cluster_name : '—' }}</td>
        </tr>
    </table>
</div>

{{-- I. Personal Information --}}
<div class="section-head">I. PERSONAL INFORMATION</div>
<table class="data-table">
    <tr>
        <td class="lbl">Full Name</td>
        <td class="val">{{ $senior->full_name }}</td>
        <td class="lbl">Date of Birth</td>
        <td class="val">{{ $senior->date_of_birth?->format('F j, Y') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Age</td>
        <td class="val">{{ $senior->age }}</td>
        <td class="lbl">Sex</td>
        <td class="val">{{ $senior->gender ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Place of Birth</td>
        <td class="val">{{ $senior->place_of_birth ?? '—' }}</td>
        <td class="lbl">Blood Type</td>
        <td class="val">{{ $senior->blood_type ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Marital Status</td>
        <td class="val">{{ $senior->marital_status ?? '—' }}</td>
        <td class="lbl">Religion</td>
        <td class="val">{{ $senior->religion ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Ethnic Origin</td>
        <td class="val">{{ $senior->ethnic_origin ?? '—' }}</td>
        <td class="lbl">Contact Number</td>
        <td class="val">{{ $senior->contact_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Encoded By</td>
        <td class="val" colspan="3">{{ $senior->encoded_by ?? '—' }}</td>
    </tr>
</table>

{{-- II. Family & Household --}}
<div class="section-head">II. FAMILY &amp; HOUSEHOLD</div>
<table class="data-table">
    <tr>
        <td class="lbl">No. of Children</td>
        <td class="val">{{ $senior->num_children ?? '—' }}</td>
        <td class="lbl">Working Children</td>
        <td class="val">{{ $senior->num_working_children ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Child Financial Support</td>
        <td class="val">{{ $senior->child_financial_support ?? '—' }}</td>
        <td class="lbl">Household Size</td>
        <td class="val">{{ $senior->household_size ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Spouse Working</td>
        <td class="val">{{ $senior->spouse_working ?? '—' }}</td>
        <td class="lbl">Living With</td>
        <td class="val">{{ osca_pdf_list($senior->living_with) }}</td>
    </tr>
    <tr>
        <td class="lbl">Household Condition</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->household_condition) }}</td>
    </tr>
</table>

{{-- III. Education, Skills & Community --}}
<div class="section-head">III. EDUCATION, SKILLS &amp; COMMUNITY</div>
<table class="data-table">
    <tr>
        <td class="lbl">Educational Attainment</td>
        <td class="val" colspan="3">{{ $senior->educational_attainment ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Specialization / Skills</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->specialization) }}</td>
    </tr>
    <tr>
        <td class="lbl">Community Service</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->community_service) }}</td>
    </tr>
</table>

{{-- IV. Economic Profile --}}
<div class="section-head">IV. ECONOMIC PROFILE</div>
<table class="data-table">
    <tr>
        <td class="lbl">Monthly Income Range</td>
        <td class="val" colspan="3">{{ $senior->monthly_income_range ? '₱ '.$senior->monthly_income_range : '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Income Sources</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->income_source) }}</td>
    </tr>
    <tr>
        <td class="lbl">Real / Immovable Assets</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->real_assets) }}</td>
    </tr>
    <tr>
        <td class="lbl">Personal / Movable Assets</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->movable_assets) }}</td>
    </tr>
    <tr>
        <td class="lbl">Problems / Needs Encountered</td>
        <td class="val" colspan="3">{{ osca_pdf_list($senior->problems_needs) }}</td>
    </tr>
</table>

{{-- V. Health Profile --}}
<div class="section-head">V. HEALTH PROFILE</div>
<table class="col-table">
    <thead>
        <tr><th style="width:28%;">Category</th><th>Findings</th></tr>
    </thead>
    <tbody>
        <tr><td>Medical Concerns</td><td>{{ osca_pdf_list($senior->medical_concern) }}</td></tr>
        <tr><td>Social / Emotional Concerns</td><td>{{ osca_pdf_list($senior->social_emotional_concern) }}</td></tr>
        <tr><td>Dental</td><td>{{ osca_pdf_list($senior->dental_concern) }}</td></tr>
        <tr><td>Vision</td><td>{{ osca_pdf_list($senior->optical_concern) }}</td></tr>
        <tr><td>Hearing</td><td>{{ osca_pdf_list($senior->hearing_concern) }}</td></tr>
        <tr><td>Healthcare Difficulty</td><td>{{ osca_pdf_list($senior->healthcare_difficulty) }}</td></tr>
        <tr>
            <td>Medical Check-up</td>
            <td>
                @if($senior->has_medical_checkup)
                    Yes — {{ $senior->checkup_schedule ?? 'schedule not specified' }}
                @else
                    No / Not scheduled
                @endif
            </td>
        </tr>
    </tbody>
</table>

{{-- VI. Health Risk Summary --}}
<div class="section-head">VI. HEALTH RISK SUMMARY</div>
@if($ml)
<table class="col-table">
    <thead>
        <tr>
            <th style="width:46%;">Domain</th>
            <th style="width:18%;" class="text-right">Score</th>
            <th style="width:36%;" class="text-center">Risk Level</th>
        </tr>
    </thead>
    <tbody>
        @foreach([
            ['Intrinsic Capacity (IC)',    $ml->ic_risk,        $ml->ic_risk_level],
            ['Environment (ENV)',          $ml->env_risk,       $ml->env_risk_level],
            ['Functional Ability (FUNC)',  $ml->func_risk,      $ml->func_risk_level],
            ['Composite Risk',             $ml->composite_risk, $ml->overall_risk_level],
        ] as [$domain, $score, $level])
        <tr>
            <td>{{ $domain }}</td>
            <td class="text-right">{{ number_format($score ?? 0, 3) }}</td>
            <td class="text-center"><span class="badge {{ osca_pdf_badge_class($level) }}">{{ strtoupper($level ?? '—') }}</span></td>
        </tr>
        @endforeach
    </tbody>
</table>
<p class="small-ref">
    Wellbeing Score: {{ number_format($ml->wellbeing_score ?? 0, 3) }}
    &nbsp;·&nbsp; Profile Group {{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
    &nbsp;·&nbsp; Assessed: {{ $ml->processed_at?->format('M j, Y g:i A') ?? '—' }}
</p>
@else
<p class="section-empty">No ML/AI health risk assessment on file for this senior.</p>
@endif

{{-- VII. Care Action Recommendations --}}
<div class="section-head">VII. CARE ACTION RECOMMENDATIONS</div>
@if($recs->count())
<table class="col-table">
    <thead>
        <tr>
            <th style="width:4%;">#</th>
            <th style="width:38%;">Recommendation</th>
            <th style="width:16%;">Responsible Office</th>
            <th style="width:10%;" class="text-center">Priority</th>
            <th style="width:14%;" class="text-center">Status</th>
            <th style="width:18%;">Reference</th>
        </tr>
    </thead>
    <tbody>
        @foreach($recs as $rec)
        @php
            $priorityLabel = match($rec->urgency ?? '') {
                'immediate', 'urgent' => 'High',
                'planned' => 'Medium',
                'maintenance' => 'Low',
                default => 'Medium',
            };
            $statusLabel = match($rec->status ?? 'pending') {
                'in_progress' => 'IN PROGRESS',
                'completed' => 'COMPLETED',
                'dismissed' => 'DISMISSED',
                default => 'PLANNED',
            };
        @endphp
        <tr>
            <td class="text-center">{{ $loop->iteration }}</td>
            <td>
                {{ $rec->action }}
                @if($rec->trigger_summary)
                <br><span class="small-ref">Trigger: {{ $rec->trigger_summary }}</span>
                @endif
            </td>
            <td>{{ $rec->service_provider ?: 'OSCA' }}</td>
            <td class="text-center">{{ $priorityLabel }}</td>
            <td class="text-center">{{ $statusLabel }}</td>
            <td class="small-ref">
                @if($rec->recommendation_code)<span class="mono">{{ $rec->recommendation_code }}</span><br>@endif
                @if($rec->evidence_source){{ $rec->evidence_source }}@endif
                @if($rec->apa_reference)<br>{{ $rec->apa_reference }}@endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p class="section-empty">No care action recommendations on file for this senior.</p>
@endif

{{-- VIII. Signatures --}}
<div class="section-head">VIII. SIGNATURES</div>
<table class="signatures">
    <tr>
        <td>
            <div class="sig-line">&nbsp;</div>
            <div class="sig-role">Prepared by<br>OSCA Encoder</div>
        </td>
        <td>
            <div class="sig-line">&nbsp;</div>
            <div class="sig-role">Verified by<br>OSCA Head</div>
        </td>
        <td>
            <div class="sig-line">&nbsp;</div>
            <div class="sig-role">Approved by<br>Municipal Social Welfare Officer</div>
        </td>
    </tr>
</table>

{{-- Static generation notice: appears once in normal document flow (unlike the
     repeating per-page "Page X of Y" footer, which is drawn on the canvas in
     SeniorCitizenController::export() — see the note in the stylesheet above). --}}
<p class="small-ref text-center" style="margin-top:14pt;">
    Generated on {{ now()->format('F j, Y g:i A') }} &nbsp;·&nbsp; AgeSense OSCA Decision Support System
</p>

</body>
</html>
