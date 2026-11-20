@if ($data['years'] === [])
    <p class="small text-body-secondary">{{ __('candidate.verification.integrity.none') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-sm small" id="integrity-years">
            <thead><tr><th>{{ __('candidate.verification.integrity.year') }}</th><th>{{ __('candidate.verification.integrity.answer') }}</th><th>{{ __('candidate.verification.integrity.view') }}</th><th>{{ __('candidate.verification.integrity.result') }}</th></tr></thead>
            <tbody>
                @foreach ($data['years'] as $line)
                    <tr data-integrity-year="{{ $line['year'] }}">
                        <td>{{ $line['year'] }}</td>
                        <td>{{ $line['answer'] ?? '—' }}</td>
                        <td>{{ $line['departmental_view'] ? __('dpc.employee_details.cr.integrity_views.'.$line['departmental_view']) : '—' }}</td>
                        <td>@if ($line['fit'] === null) — @else<x-fit-mark :fit="$line['fit']" />@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
