@props([
    'control'   => null,
    'note'      => 'System-generated decision-support output. Indicates possible risk for prioritisation; it is not a clinical diagnosis.',
    'signatory' => 'Reviewed by',
])
@php
    $control = $control ?? 'OSCA-PSG-' . now()->format('Ymd-His');
@endphp
{{-- Official document footer for senior records and reports: control number,
     system-generated provenance, and a signatory line. Chrome for paper only —
     hidden on screen, revealed when printing / saving to PDF via the project's
     print utilities (.print-only in resources/css/app.css). --}}
<footer {{ $attributes->merge(['class' => 'doc-footer hidden print-only']) }}>
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-5">
        <div class="space-y-1 min-w-0">
            <div>Control No. <span class="doc-footer-control">{{ $control }}</span></div>
            <div>Generated {{ now()->format('F j, Y · g:i A') }} &middot; Office of Senior Citizens Affairs, Pagsanjan, Laguna</div>
            <div class="max-w-prose">{{ $note }}</div>
        </div>
        <div class="text-right">
            <div class="doc-footer-sign">{{ $signatory }}</div>
        </div>
    </div>
</footer>
