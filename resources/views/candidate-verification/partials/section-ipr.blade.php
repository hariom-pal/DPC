<div class="table-responsive">
    <table class="table table-sm small" id="ipr-years">
        <thead><tr><th>{{ __('candidate.verification.ipr.year') }}</th><th>{{ __('candidate.verification.ipr.state') }}</th><th>{{ __('candidate.verification.ipr.reason') }}</th></tr></thead>
        <tbody>
            @foreach ($data['years'] as $line)
                <tr data-ipr-year="{{ $line['year'] }}" data-state="{{ $line['state'] }}">
                    <td>{{ $line['year'] }}</td>
                    <td>
                        @if ($line['state'] === 'filed'){{ __('candidate.verification.ipr.filed', ['date' => $line['filed_on']]) }}@if ($line['late']) <span class="badge text-bg-warning">{{ __('candidate.verification.ipr.late') }}</span>@endif
                        @else{{ __('candidate.verification.ipr.'.$line['state']) }}
                        @endif
                    </td>
                    <td>{{ $line['missing_reason'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
