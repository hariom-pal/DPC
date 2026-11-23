{{-- The DPC Chairman's grading changes at the sitting, each with its reason (2026-11-22). --}}
@if ($changes !== [])
    <div class="small mt-2" id="chairman-grade-changes">
        <div class="fw-semibold">{{ __('dpc.employee_details.cr.chairman_changes') }}</div>
        <ul class="mb-0 ps-3">
            @foreach ($changes as $change)
                <li data-grade-change-year="{{ $change['year'] }}">
                    {{ __('dpc.employee_details.cr.chairman_change_line', ['year' => $change['year'], 'from' => $change['from'], 'to' => $change['to'], 'by' => $change['by'] ?? '—', 'at' => $change['at']->format('d-m-Y H:i')]) }}
                    — <span class="text-body-secondary">{{ $change['reason'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
