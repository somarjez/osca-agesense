<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1e293b; background: #fff; }
.page { padding: 32px 36px; }

/* Senior name block */
.senior-name-block { background: #f5f5f4; border: 1px solid #e2e1dd; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; display: table; width: 100%; }
.senior-name-block .snb-left { display: table-cell; vertical-align: middle; width: 75%; }
.senior-name-block .snb-right { display: table-cell; vertical-align: middle; text-align: right; width: 25%; }
.senior-name { font-size: 17px; font-weight: bold; color: #111827; }
.senior-meta { font-size: 10px; color: #475569; margin-top: 3px; }
.risk-badge { padding: 4px 10px; border-radius: 99px; font-size: 10px; font-weight: bold; border: 1px solid transparent; }
.risk-HIGH     { background: #1f2937; color: #ffffff; border-color: #1f2937; }
.risk-MODERATE { background: #6b7280; color: #ffffff; border-color: #6b7280; }
.risk-LOW      { background: #ffffff; color: #374151; border-color: #9ca3af; }
.risk-NONE     { background: #f3f4f6; color: #9ca3af; border-color: #e5e7eb; }

/* Section */
.section { margin-bottom: 14px; }
.section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; }

/* Grid */
.grid-2 { display: table; width: 100%; }
.grid-2 .col { display: table-cell; width: 50%; vertical-align: top; padding-right: 12px; }
.grid-2 .col:last-child { padding-right: 0; padding-left: 12px; }
.grid-3 { display: table; width: 100%; }
.grid-3 .col { display: table-cell; width: 33.33%; vertical-align: top; padding-right: 8px; }
.grid-3 .col:last-child { padding-right: 0; }

/* Field */
.field { margin-bottom: 6px; }
.field .label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }
.field .value { font-size: 11px; color: #1e293b; margin-top: 1px; }
.field .value.empty { color: #cbd5e1; }

/* Tag list */
.tags { margin-top: 3px; }
.tag { display: inline-block; background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; font-size: 9px; padding: 2px 6px; border-radius: 99px; margin: 1px 2px 1px 0; }
.tag-teal   { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
.tag-amber  { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
.tag-red    { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
.tag-sky    { background: #f8fafc; color: #475569; border-color: #e2e8f0; }

/* Risk score table */
.score-table { width: 100%; border-collapse: collapse; }
.score-table th { font-size: 9px; text-transform: uppercase; color: #94a3b8; text-align: left; padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
.score-table td { font-size: 10px; padding: 4px 6px; border-bottom: 1px solid #f8fafc; }
.score-bar-wrap { background: #f1f5f9; border-radius: 99px; height: 5px; width: 80px; display: inline-block; vertical-align: middle; }
.score-bar { height: 5px; border-radius: 99px; }

/* Recommendations */
.rec-item { padding: 5px 0; border-bottom: 1px solid #f1f5f9; display: table; width: 100%; }
.rec-item:last-child { border-bottom: none; }
.rec-num { display: table-cell; font-size: 9px; color: #94a3b8; width: 20px; vertical-align: top; padding-top: 1px; }
.rec-text { display: table-cell; font-size: 10px; color: #334155; }
.rec-urg { display: table-cell; width: 80px; text-align: right; vertical-align: top; }
.urgency { font-size: 8px; font-weight: bold; padding: 1px 5px; border-radius: 99px; white-space: nowrap; }
.urg-immediate { background: #1f2937; color: #ffffff; }
.urg-urgent    { background: #4b5563; color: #ffffff; }
.urg-planned   { background: #e5e7eb; color: #374151; }
.urg-maintenance { background: #f3f4f6; color: #6b7280; }

/* Footer (old simple) */
.footer { border-top: 1px solid #e2e8f0; margin-top: 8px; padding-top: 8px; display: table; width: 100%; }
.footer .f-left { display: table-cell; font-size: 8px; color: #94a3b8; }
.footer .f-right { display: table-cell; font-size: 8px; color: #94a3b8; text-align: right; }

/* Formal letterhead */
.letterhead { display: table; width: 100%; border-bottom: 2px solid #111827; padding-bottom: 12px; margin-bottom: 6px; }
.letterhead .lh-left { display: table-cell; vertical-align: middle; width: 70%; }
.letterhead .lh-right { display: table-cell; vertical-align: middle; text-align: right; width: 30%; }
.letterhead .lh-org { font-size: 14px; font-weight: bold; color: #111827; letter-spacing: 0.02em; }
.letterhead .lh-sub { font-size: 9.5px; color: #475569; margin-top: 2px; }
.letterhead .lh-title { font-size: 13px; font-weight: bold; color: #1e293b; }
.letterhead .lh-meta { font-size: 8.5px; color: #94a3b8; margin-top: 2px; }
.confidential { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 14px; }
/* Signature + footer */
.signatures { display: table; width: 100%; margin-top: 28px; }
.signatures .sig { display: table-cell; width: 50%; vertical-align: bottom; padding-right: 24px; }
.signatures .sig:last-child { padding-right: 0; padding-left: 24px; }
.sig-line { border-top: 1px solid #475569; margin-top: 32px; padding-top: 4px; font-size: 9px; color: #475569; }
.doc-footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 8px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<div class="page">

@php
    $ml   = $senior->latestMlResult;
    $qol  = $senior->latestQolSurvey;
    $recs = $ml?->recommendations ?? collect();
    $riskLevel = $ml?->overall_risk_level ?? 'NONE';
@endphp

{{-- Formal letterhead --}}
<div class="letterhead">
    <div class="lh-left">
        <div class="lh-org">Office for Senior Citizens Affairs (OSCA)</div>
        <div class="lh-sub">Senior Citizens Welfare and Records Management</div>
    </div>
    <div class="lh-right">
        <div class="lh-title">Senior Citizen Profile Report</div>
        <div class="lh-meta" style="{{ $senior->official_osca_id ? 'font-weight:600; color:#334155;' : 'font-style:italic;' }}">OSCA ID: {{ $senior->official_osca_id_display }}</div>
        <div class="lh-meta">System ID: {{ $senior->osca_id }}</div>
        <div class="lh-meta">Generated on {{ now()->format('F j, Y') }}</div>
    </div>
</div>
<div class="confidential">Confidential — for official OSCA use only</div>

{{-- Name block --}}
<div class="senior-name-block">
    <div class="snb-left">
        <div class="senior-name">{{ $senior->full_name }}</div>
        <div class="senior-meta">
            @if($senior->official_osca_id)
                <strong style="color:#1e293b;">OSCA ID: {{ $senior->official_osca_id_display }}</strong>
            @else
                <span style="font-style:italic;">OSCA ID: {{ $senior->official_osca_id_display }}</span>
            @endif
            &nbsp;·&nbsp; System ID: {{ $senior->osca_id }}
            &nbsp;·&nbsp; {{ $senior->barangay }}
            &nbsp;·&nbsp; Age {{ $senior->age }}
            @if($senior->gender) &nbsp;·&nbsp; {{ $senior->gender }} @endif
        </div>
    </div>
    <div class="snb-right">
        <span class="risk-badge risk-{{ $riskLevel }}">
            @if($riskLevel === 'NONE') No ML Data
            @else Risk: {{ $riskLevel }} @endif
        </span>
        @if($ml?->cluster_name)
        <div style="text-align:right; margin-top:4px; font-size:9px; color:#64748b;">
            Profile Group {{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
        </div>
        @endif
    </div>
</div>

{{-- I. Personal Information --}}
<div class="section">
    <div class="section-title">I. Personal Information</div>
    <div class="grid-3">
        <div class="col">
            <div class="field"><div class="label">Date of Birth</div><div class="value">{{ $senior->date_of_birth?->format('F j, Y') ?? '—' }}</div></div>
            <div class="field"><div class="label">Place of Birth</div><div class="value {{ $senior->place_of_birth ? '' : 'empty' }}">{{ $senior->place_of_birth ?? '—' }}</div></div>
            <div class="field"><div class="label">Blood Type</div><div class="value {{ $senior->blood_type ? '' : 'empty' }}">{{ $senior->blood_type ?? '—' }}</div></div>
        </div>
        <div class="col">
            <div class="field"><div class="label">Marital Status</div><div class="value {{ $senior->marital_status ? '' : 'empty' }}">{{ $senior->marital_status ?? '—' }}</div></div>
            <div class="field"><div class="label">Religion</div><div class="value {{ $senior->religion ? '' : 'empty' }}">{{ $senior->religion ?? '—' }}</div></div>
            <div class="field"><div class="label">Ethnic Origin</div><div class="value {{ $senior->ethnic_origin ? '' : 'empty' }}">{{ $senior->ethnic_origin ?? '—' }}</div></div>
        </div>
        <div class="col">
            <div class="field"><div class="label">Contact Number</div><div class="value {{ $senior->contact_number ? '' : 'empty' }}">{{ $senior->contact_number ?? '—' }}</div></div>
            <div class="field"><div class="label">Encoded By</div><div class="value {{ $senior->encoded_by ? '' : 'empty' }}">{{ $senior->encoded_by ?? '—' }}</div></div>
        </div>
    </div>
</div>

{{-- II. Family / Household --}}
<div class="section">
    <div class="section-title">II. Family &amp; Household</div>
    <div class="grid-3">
        <div class="col">
            <div class="field"><div class="label">No. of Children</div><div class="value">{{ $senior->num_children }}</div></div>
            <div class="field"><div class="label">Working Children</div><div class="value">{{ $senior->num_working_children }}</div></div>
            <div class="field"><div class="label">Child Financial Support</div><div class="value {{ $senior->child_financial_support ? '' : 'empty' }}">{{ $senior->child_financial_support ?? '—' }}</div></div>
        </div>
        <div class="col">
            <div class="field"><div class="label">Household Size</div><div class="value">{{ $senior->household_size }}</div></div>
            <div class="field"><div class="label">Spouse Working</div><div class="value {{ $senior->spouse_working ? '' : 'empty' }}">{{ $senior->spouse_working ?? '—' }}</div></div>
        </div>
        <div class="col">
            <div class="field">
                <div class="label">Living With</div>
                <div class="tags">
                    @forelse($senior->living_with ?? [] as $v)
                    <span class="tag">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
            <div class="field">
                <div class="label">Household Condition</div>
                <div class="tags">
                    @forelse($senior->household_condition ?? [] as $v)
                    <span class="tag tag-amber">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

{{-- III. Education / Skills / Community --}}
<div class="section">
    <div class="section-title">III. Education, Skills &amp; Community</div>
    <div class="grid-3">
        <div class="col">
            <div class="field"><div class="label">Educational Attainment</div><div class="value {{ $senior->educational_attainment ? '' : 'empty' }}">{{ $senior->educational_attainment ?? '—' }}</div></div>
        </div>
        <div class="col">
            <div class="field">
                <div class="label">Specialization / Skills</div>
                <div class="tags">
                    @forelse($senior->specialization ?? [] as $v)
                    <span class="tag tag-teal">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col">
            <div class="field">
                <div class="label">Community Service</div>
                <div class="tags">
                    @forelse($senior->community_service ?? [] as $v)
                    <span class="tag tag-teal">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

{{-- V. Economic --}}
<div class="section">
    <div class="section-title">V. Economic Profile</div>
    <div class="grid-3">
        <div class="col">
            <div class="field"><div class="label">Monthly Income Range</div><div class="value {{ $senior->monthly_income_range ? '' : 'empty' }}">{{ $senior->monthly_income_range ? '₱'.$senior->monthly_income_range : '—' }}</div></div>
            <div class="field">
                <div class="label">Income Sources</div>
                <div class="tags">
                    @forelse($senior->income_source ?? [] as $v)
                    <span class="tag tag-teal">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col">
            <div class="field">
                <div class="label">Real / Immovable Assets</div>
                <div class="tags">
                    @forelse($senior->real_assets ?? [] as $v)
                    <span class="tag">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
            <div class="field">
                <div class="label">Personal / Movable Assets</div>
                <div class="tags">
                    @forelse($senior->movable_assets ?? [] as $v)
                    <span class="tag">{{ $v }}</span>
                    @empty <span class="value empty">—</span>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col">
            @if(!empty($senior->problems_needs))
            <div class="field">
                <div class="label">Problems / Needs Encountered</div>
                <div class="tags">
                    @foreach($senior->problems_needs as $v)
                    <span class="tag tag-amber">{{ $v }}</span>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- VI. Health --}}
<div class="section">
    <div class="section-title">VI. Health Profile</div>
    <div class="grid-2">
        <div class="col">
            <div class="field">
                <div class="label">Medical Concerns</div>
                <div class="tags">
                    @forelse($senior->medical_concern ?? [] as $v)
                    <span class="tag tag-red">{{ $v }}</span>
                    @empty <span class="value empty">None reported</span>
                    @endforelse
                </div>
            </div>
            <div class="field">
                <div class="label">Social / Emotional Concerns</div>
                <div class="tags">
                    @forelse($senior->social_emotional_concern ?? [] as $v)
                    <span class="tag tag-amber">{{ $v }}</span>
                    @empty <span class="value empty">None reported</span>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col">
            @foreach([
                ['Dental',     $senior->dental_concern ?? []],
                ['Optical',    $senior->optical_concern ?? []],
                ['Hearing',    $senior->hearing_concern ?? []],
                ['HC Difficulty', $senior->healthcare_difficulty ?? []],
            ] as [$lbl, $items])
            @if(!empty($items))
            <div class="field">
                <div class="label">{{ $lbl }}</div>
                <div class="tags">
                    @foreach($items as $v)<span class="tag tag-sky">{{ $v }}</span>@endforeach
                </div>
            </div>
            @endif
            @endforeach
            <div class="field">
                <div class="label">Medical Check-up</div>
                <div class="value">
                    @if($senior->has_medical_checkup) Yes — {{ $senior->checkup_schedule ?? 'schedule not specified' }}
                    @else No / Not scheduled @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ML Risk Analysis --}}
@if($ml)
<div class="section">
    <div class="section-title">Health Risk Indicator Summary &nbsp;·&nbsp; Assessed: {{ $ml->processed_at?->format('M j, Y H:i') }}</div>
    <table class="score-table">
        <thead>
            <tr>
                <th>Domain</th>
                <th>Score</th>
                <th>Visual</th>
                <th>Level</th>
            </tr>
        </thead>
        <tbody>
            @foreach([
                ['Intrinsic Capacity (IC)',    $ml->ic_risk,       $ml->ic_risk_level],
                ['Environment (ENV)',          $ml->env_risk,      $ml->env_risk_level],
                ['Functional Ability (FUNC)',  $ml->func_risk,     $ml->func_risk_level],
                ['Composite Risk',             $ml->composite_risk,$ml->overall_risk_level],
            ] as [$domain, $score, $level])
            @php
                $pct = round(($score ?? 0) * 100);
                $barColor = match(strtoupper($level ?? '')) {
                    'HIGH'    => '#374151',
                    'MODERATE'=> '#6b7280',
                    'LOW'     => '#9ca3af',
                    default   => '#cbd5e1',
                };
            @endphp
            <tr>
                <td>{{ $domain }}</td>
                <td>{{ number_format($score ?? 0, 3) }}</td>
                <td>
                    <div class="score-bar-wrap">
                        <div class="score-bar" style="width:{{ $pct }}%; background:{{ $barColor }};"></div>
                    </div>
                </td>
                <td><span class="risk-badge risk-{{ strtoupper($level ?? 'NONE') }}">{{ strtoupper($level ?? '—') }}</span></td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="margin-top:6px; font-size:9px; color:#94a3b8;">
        Wellbeing Score: {{ number_format($ml->wellbeing_score ?? 0, 3) }}
        &nbsp;·&nbsp; Profile Group {{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
    </div>
</div>
@endif

{{-- Recommendations --}}
@if($recs->count())
<div class="section">
    <div class="section-title">Care Action Recommendations ({{ $recs->count() }} items)</div>
    @php $shown = $recs->take(20); @endphp
    @foreach($shown as $rec)
    @php
        $urgClass = match($rec->urgency ?? '') {
            'immediate'   => 'urg-immediate',
            'urgent'      => 'urg-urgent',
            'planned'     => 'urg-planned',
            'maintenance' => 'urg-maintenance',
            default       => 'urg-planned',
        };
    @endphp
    <div class="rec-item">
        <div class="rec-num">{{ $loop->iteration }}.</div>
        <div class="rec-text">
            {{ $rec->action }}
            @if($rec->recommendation_code || $rec->trigger_summary)
            <div style="font-size:8px; color:#94a3b8; margin-top:2px;">
                @if($rec->recommendation_code)<strong style="color:#64748b;">{{ $rec->recommendation_code }}</strong>@endif
                @if($rec->domain) · {{ $rec->domain }}@endif
                @if($rec->trigger_summary) · {{ $rec->trigger_summary }}@endif
            </div>
            @endif
            @if($rec->evidence_source)
            <div style="font-size:8px; color:#94a3b8;">Source: {{ $rec->evidence_source }}@if($rec->apa_reference) — {{ $rec->apa_reference }}@endif</div>
            @endif
        </div>
        <div class="rec-urg"><span class="urgency {{ $urgClass }}">{{ strtoupper($rec->urgency ?? 'planned') }}</span></div>
    </div>
    @endforeach
    @if($recs->count() > 20)
    <div style="font-size:9px; color:#94a3b8; margin-top:4px;">... and {{ $recs->count() - 20 }} more items (view full report in system).</div>
    @endif
</div>
@endif

    <div class="signatures">
        <div class="sig">
            <div class="sig-line">Prepared by (OSCA Encoder)</div>
        </div>
        <div class="sig">
            <div class="sig-line">Verified by (OSCA Head)</div>
        </div>
    </div>
    <div class="doc-footer">
        Generated on {{ now()->format('F j, Y g:i A') }} · {{ auth()->user()->name ?? 'OSCA System' }} · AgeSense OSCA Decision-Support System
    </div>

</div>
</body>
</html>
