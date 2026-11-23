{{-- 2026-11-22: the CR years missing, each with the reason the department recorded. --}}
@if ($data['missing'] !== [])
    <div class="alert alert-warning small" id="cr-missing">
        <div class="fw-semibold mb-1">{{ __('candidate.verification.cr.missing_heading') }}</div>
        <ul class="mb-0 ps-3">
            @foreach ($data['missing'] as $gap)
                <li data-missing-year="{{ $gap['year'] }}">
                    {{ $gap['year'] }} — {{ __('candidate.verification.cr.missing_states.'.$gap['state']) }}:
                    {{ $gap['reason'] ?? __('candidate.verification.cr.missing_no_reason') }}@if ($gap['remarks']) ({{ $gap['remarks'] }})@endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
@if ($data['appointed_year'])
    <p class="small text-body-secondary">{{ __('candidate.verification.cr.appointed', ['year' => $data['appointed_year']]) }}</p>
@endif
<div class="table-responsive">
    <table class="table table-sm small" id="cr-years">
        <thead><tr><th>{{ __('candidate.verification.cr.year') }}</th><th>{{ __('candidate.verification.cr.state') }}</th><th>{{ __('candidate.verification.cr.grade') }}</th><th>{{ __('candidate.verification.cr.counted') }}</th></tr></thead>
        <tbody>
            @foreach ($data['years'] as $line)
                <tr data-cr-year="{{ $line['year'] }}" data-state="{{ $line['state'] }}">
                    <td>{{ $line['year'] }}</td>
                    <td>
                        {{ __('candidate.verification.cr.'.$line['state']) }}
                        @if ($line['missing_reason'])<div class="text-body-secondary">{{ $line['missing_reason'] }}</div>@endif
                        @if ($line['tribal_area'])<span class="badge text-bg-info">{{ __('candidate.verification.cr.tribal') }}</span>@endif
                    </td>
                    <td>{{ $line['grade'] ?? '—' }}</td>
                    <td>
                        @if ($line['before_appointment']){{ __('candidate.verification.cr.before_appointment') }}
                        @elseif ($line['skipped']){{ __('candidate.verification.cr.replaced') }}
                        @elseif ($line['counted'])✓
                        @else —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@if ($data['score'] === null)
    <p class="small text-body-secondary">{{ __('candidate.verification.cr.no_rule') }}</p>
@else
    <div class="small" id="cr-score">
        <strong>{{ __('candidate.verification.cr.score') }}</strong> —
        {{ __('candidate.verification.cr.marks') }}:
        @foreach ($data['score']['marks_by_year'] as $year => $mark){{ $year }}: {{ $mark }}@if (! $loop->last), @endif @endforeach
        @if ($data['score']['average_filled_years'] > 0)
            <div id="cr-average">{{ __('candidate.verification.cr.average', ['mark' => $data['score']['average_fill_mark'], 'years' => $data['score']['average_filled_years'], 'contribution' => $data['score']['average_contribution']]) }}</div>
        @endif
        @if ($data['score']['tribal_bonus'] > 0)<div>{{ __('candidate.verification.cr.tribal_bonus', ['bonus' => $data['score']['tribal_bonus']]) }}</div>@endif
        <div class="fw-semibold">{{ __('candidate.verification.cr.total', ['total' => $data['score']['total']]) }}</div>
    </div>
@endif
