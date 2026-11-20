<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('candidate.verification.ack.title') }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 15px; margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        td, th { border: 1px solid #999; padding: 4px; text-align: left; vertical-align: top; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>{{ __('candidate.verification.ack.heading') }}</h1>
    <p>{{ __('candidate.verification.ack.dpc', ['dpc' => $snapshot['dpc'] ?? '—']) }}<br>
        {{ __('candidate.verification.ack.candidate', ['name' => $snapshot['candidate']['name'] ?? '—', 'employee' => $snapshot['candidate']['employee_number'] ?? '—', 'sn' => $snapshot['candidate']['seniority_number'] ?? '—']) }}<br>
        {{ __('candidate.verification.ack.cycle', ['cycle' => $snapshot['cycle'] ?? '—']) }}<br>
        {{ __('candidate.verification.ack.signed', ['date' => $participant->submitted_at?->format('d-m-Y H:i') ?? '—', 'signature' => $participant->signature_id]) }}</p>
    <table>
        <tr><th>{{ __('candidate.verification.review.section') }}</th><th>{{ __('candidate.verification.review.decision') }}</th></tr>
        @foreach (($snapshot['decisions'] ?? []) as $section => $decision)
            <tr><td>{{ \App\Domain\CandidateVerification\Enums\VerificationSection::from($section)->label() }}</td><td>{{ $participant->decisionLabel($decision) }}</td></tr>
        @endforeach
    </table>
    @if (! empty($snapshot['grievances']))
        <table>
            <tr><th>{{ __('candidate.verification.review.section') }}</th><th>{{ __('candidate.grievance.category') }}</th><th>{{ __('candidate.verification.cr.year') }}</th><th>{{ __('candidate.grievance.remark') }}</th><th>{{ __('candidate.verification.ack.document') }}</th></tr>
            @foreach ($snapshot['grievances'] as $g)
                <tr><td>{{ \App\Domain\CandidateVerification\Enums\VerificationSection::from($g['section'])->label() }}</td><td>{{ __('candidate.grievance.categories.'.$g['category']) }}</td><td>{{ $g['related_year'] ?? '—' }}</td><td>{{ $g['remark'] }}</td><td>{{ $g['document'] }}<br><span class="muted">{{ $g['document_sha256'] }}</span></td></tr>
            @endforeach
        </table>
        <p>{{ __('candidate.grievance.undertaking_label') }}: {{ $snapshot['undertaking'] ?? '' }}</p>
    @endif
    <p class="muted">{{ __('candidate.verification.ack.hash', ['hash' => $participant->snapshot_sha256]) }}</p>
</body>
</html>
