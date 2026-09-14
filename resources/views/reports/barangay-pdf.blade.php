<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Barangay Senior Citizen Report</title>
<style>
@page { margin: 1in; }
/* Explicit element list, not a bare `*` universal selector — Dompdf's fixed-position
   page-footer rendering silently drops the footer on every page when a universal
   selector is present anywhere in the stylesheet (see resources/views/seniors/pdf.blade.php). */
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

.gov-header { text-align: center; padding-bottom: 6pt; }
.gov-header .republic { font-size: 11pt; color: #333333; }
.gov-header .municipality { font-size: 11pt; color: #333333; }
.gov-header .office { font-size: 12pt; font-weight: bold; color: #333333; margin-top: 2pt; }
.gov-header .report-title { font-size: 18pt; font-weight: bold; color: #0D47A1; margin-top: 10pt; letter-spacing: 0.02em; }
.gov-header .report-subtitle { font-size: 12pt; color: #333333; margin-top: 2pt; }
.hr-rule { border: none; border-top: 1pt solid #0D47A1; margin: 8pt 0 10pt 0; }

table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
table.data-table td, table.data-table th { border: 0.5pt solid #333333; padding: 4pt 6pt; font-size: 10pt; vertical-align: top; }
table.data-table td.lbl { width: 22%; font-weight: bold; background: #F2F2F2; }
table.data-table td.val { width: 28%; font-weight: normal; }

table.col-table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
table.col-table th {
    background: #0D47A1; color: #ffffff; font-size: 10pt; font-weight: bold;
    text-align: left; padding: 5pt 6pt; border: 0.5pt solid #0D47A1;
}
table.col-table td { border: 0.5pt solid #333333; padding: 5pt 6pt; font-size: 10pt; vertical-align: top; }
table.col-table tbody tr:nth-child(even) { background: #F2F2F2; }
table.col-table tr { page-break-inside: avoid; }

table.meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
table.meta-table td { border: 0.5pt solid #333333; padding: 4pt 6pt; font-size: 10pt; }
table.meta-table td.lbl { width: 18%; font-weight: bold; background: #F2F2F2; }
table.meta-table td.val { width: 32%; }

.section-head {
    background: #0D47A1; color: #ffffff; font-size: 13pt; font-weight: bold;
    padding: 5pt 8pt; margin-top: 16pt; margin-bottom: 8pt;
    page-break-after: avoid;
}
.section-empty { font-size: 10pt; color: #333333; font-style: italic; margin-bottom: 10pt; }

.badge {
    display: inline-block; padding: 2pt 7pt; font-size: 9pt; font-weight: bold;
    border: 0.75pt solid #333333; text-align: center; white-space: nowrap;
}
.badge-high      { background: #0D47A1; color: #ffffff; border-color: #0D47A1; }
.badge-moderate  { background: #333333; color: #ffffff; border-color: #333333; }
.badge-low       { background: #ffffff; color: #333333; border-color: #333333; }
.badge-none      { background: #F2F2F2; color: #333333; border-color: #F2F2F2; }

.text-center { text-align: center; }
.small-ref { font-size: 8.5pt; color: #333333; }
.mono { font-family: 'DejaVu Sans Mono', monospace; }

.signatures { width: 100%; border-collapse: collapse; margin-top: 30pt; page-break-inside: avoid; }
.signatures td { width: 33.33%; padding: 0 10pt; text-align: center; vertical-align: bottom; font-size: 10pt; }
.sig-line { border-top: 0.75pt solid #333333; margin-top: 40pt; padding-top: 4pt; }
.sig-role { font-size: 8.5pt; color: #333333; margin-top: 2pt; }

/* Note: the repeating "Page X of Y" footer is drawn on the canvas in
   ReportController::exportBarangay(), not via CSS counter(pages) — see the
   note in resources/views/seniors/pdf.blade.php for why. */
</style>
</head>
<body>

@php
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

    $total    = $seniors->count();
    $surveyed = $seniors->filter(fn ($s) => $s->latestMlResult !== null)->count();
    $high     = $riskDist['HIGH'] ?? 0;
    $moderate = $riskDist['MODERATE'] ?? 0;
    $low      = $riskDist['LOW'] ?? 0;
    $clusterTotal = $clusterDist->sum('count');
@endphp

{{-- Header / letterhead --}}
<div class="gov-header">
    <div class="republic">Republic of the Philippines</div>
    <div class="municipality">Municipality of Pagsanjan</div>
    <div class="office">Office for Senior Citizens Affairs</div>
    <div class="report-title">BARANGAY SENIOR CITIZEN REPORT</div>
    <div class="report-subtitle">{{ $brgy }}</div>
</div>
<hr class="hr-rule">

{{-- Metadata table --}}
<table class="meta-table">
    <tr>
        <td class="lbl">Barangay</td>
        <td class="val">{{ $brgy }}</td>
        <td class="lbl">Generated Date</td>
        <td class="val">{{ now()->format('F j, Y') }}</td>
    </tr>
    <tr>
        <td class="lbl">Total Seniors</td>
        <td class="val">{{ $total }}</td>
        <td class="lbl">Classification</td>
        <td class="val">Confidential — Official OSCA Use Only</td>
    </tr>
</table>

{{-- I. KPI Summary --}}
<div class="section-head">I. KPI SUMMARY</div>
<table class="data-table">
    <tr>
        <td class="lbl">Total Seniors</td>
        <td class="val">{{ $total }}</td>
        <td class="lbl">Analysed</td>
        <td class="val">{{ $surveyed }}</td>
    </tr>
    <tr>
        <td class="lbl">HIGH Risk</td>
        <td class="val"><span class="badge badge-high">{{ $high }}</span></td>
        <td class="lbl">Urgent Cases</td>
        <td class="val">{{ $urgentCount }}</td>
    </tr>
    <tr>
        <td class="lbl">MODERATE Risk</td>
        <td class="val"><span class="badge badge-moderate">{{ $moderate }}</span></td>
        <td class="lbl">LOW Risk</td>
        <td class="val"><span class="badge badge-low">{{ $low }}</span></td>
    </tr>
</table>

{{-- II. Average Domain Risk Scores --}}
<div class="section-head">II. AVERAGE DOMAIN RISK SCORES</div>
<table class="col-table">
    <thead>
        <tr><th style="width:60%;">Domain</th><th>Average Score</th></tr>
    </thead>
    <tbody>
        <tr><td>Intrinsic Capacity (IC)</td><td>{{ number_format(($domainAvgs?->ic ?? 0) * 100, 1) }}%</td></tr>
        <tr><td>Environment</td><td>{{ number_format(($domainAvgs?->env ?? 0) * 100, 1) }}%</td></tr>
        <tr><td>Functional Ability</td><td>{{ number_format(($domainAvgs?->func ?? 0) * 100, 1) }}%</td></tr>
        <tr><td>Composite</td><td>{{ number_format(($domainAvgs?->composite ?? 0) * 100, 1) }}%</td></tr>
    </tbody>
</table>

{{-- III. Profile Group Distribution --}}
<div class="section-head">III. PROFILE GROUP DISTRIBUTION</div>
@if($clusterDist->isEmpty())
<p class="section-empty">No profile group data available for this barangay.</p>
@else
<table class="col-table">
    <thead>
        <tr><th>Group</th><th>Name</th><th>Count</th><th>Percent</th></tr>
    </thead>
    <tbody>
        @foreach($clusterDist as $c)
        @php $pct = $clusterTotal > 0 ? $c->count / $clusterTotal * 100 : 0; @endphp
        <tr>
            <td>{{ $c->cluster_named_id }}</td>
            <td>{{ $c->cluster_name }}</td>
            <td>{{ $c->count }}</td>
            <td>{{ number_format($pct, 1) }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- IV. Pending Recommendations by Category --}}
<div class="section-head">IV. PENDING RECOMMENDATIONS BY CATEGORY</div>
@if($pendingRecs->isEmpty())
<p class="section-empty">No pending recommendations for this barangay.</p>
@else
<table class="col-table">
    <thead>
        <tr><th style="width:70%;">Category</th><th>Count</th></tr>
    </thead>
    <tbody>
        @foreach($pendingRecs as $rec)
        <tr>
            <td>{{ \App\Support\RecommendationCategories::label($rec->category) }}</td>
            <td>{{ $rec->count }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- V. Senior Roster --}}
<div class="section-head">V. SENIOR CITIZEN ROSTER ({{ $total }})</div>
<table class="col-table">
    <thead>
        <tr>
            <th>Name</th><th>OSCA ID</th><th>Age</th><th>Gender</th>
            <th>Risk Level</th><th>Composite</th><th>Profile Group</th>
        </tr>
    </thead>
    <tbody>
        @forelse($seniors as $senior)
        @php $ml = $senior->latestMlResult; @endphp
        <tr>
            <td>{{ $senior->full_name }}</td>
            <td>{{ $senior->official_osca_id_display }}</td>
            <td>{{ $senior->age }}</td>
            <td>{{ $senior->gender ?? '—' }}</td>
            <td>
                @if($ml)
                    <span class="badge {{ osca_pdf_badge_class($ml->overall_risk_level) }}">{{ strtoupper($ml->overall_risk_level) }}</span>
                @else
                    —
                @endif
            </td>
            <td>{{ $ml ? number_format($ml->composite_risk * 100, 1).'%' : '—' }}</td>
            <td>{{ $ml?->cluster_name ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="7" class="text-center">No active seniors registered in {{ $brgy }}.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- VI. Signatures --}}
<div class="section-head">VI. SIGNATURES</div>
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

<p class="small-ref text-center" style="margin-top:14pt;">
    Generated on {{ now()->format('F j, Y g:i A') }} &nbsp;·&nbsp; AgeSense OSCA Decision Support System
</p>

</body>
</html>
