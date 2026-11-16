{{--
    Step 1 of a Vacancy Management proposal: the AO's Working Officers List against the
    finalized record. Figures that differ are marked, and so is each row that does not
    agree with B1/B2; the Seniority List stays closed until everything agrees.
    Expects: $reconciliation (ProposalVacancyPosition::reconcile()).
--}}
<div id="working-list-reconciliation" data-reconciled="{{ $reconciliation['matches'] ? 'yes' : 'no' }}">
    <h3 class="h6">{{ __('vacancymaster.proposal.working_list.heading') }}</h3>
    @if ($reconciliation['not_needed'] ?? false)
        {{-- Working strength zero: no officer to list, so no list is asked for (2026-11-16). --}}
        <div class="alert alert-info py-2 small mb-0" id="working-list-not-needed">{{ __('vacancymaster.proposal.working_list.not_needed') }}</div>
    @elseif ($reconciliation['uploaded'] === null)
        <div class="alert alert-warning py-2 small mb-0" id="working-list-missing">{{ __('vacancymaster.proposal.working_list.missing') }}</div>
    @elseif ($reconciliation['record'] !== null)
        <div class="table-responsive">
            <table class="table table-sm align-middle small mb-2">
                <thead><tr>
                    <th>{{ __('vacancymaster.proposal.working_list.figure') }}</th>
                    <th class="text-end">{{ __('vacancymaster.proposal.working_list.uploaded') }}</th>
                    <th class="text-end">{{ __('vacancymaster.proposal.working_list.record') }}</th>
                    <th class="text-end">{{ __('vacancymaster.proposal.working_list.difference') }}</th>
                </tr></thead>
                <tbody>
                    {{-- A record kept per Pre-DPC (2026-10-30) compares the total and the officers here on deputation only. --}}
                    @foreach ($reconciliation['figures'] ?? ['promotion', 'direct', 'deputation', 'total'] as $figure)
                        @php
                            $differs = isset($reconciliation['differences'][$figure]);
                        @endphp
                        <tr class="{{ $differs ? 'table-danger' : '' }}" data-working-figure="{{ $figure }}" data-differs="{{ $differs ? 'yes' : 'no' }}">
                            <td>{{ __('vacancymaster.proposal.working_list.figures.'.$figure) }}</td>
                            <td class="text-end">{{ $reconciliation['uploaded'][$figure] }}</td>
                            <td class="text-end">{{ $reconciliation['record'][$figure] }}</td>
                            <td class="text-end">{{ $differs ? sprintf('%+d', $reconciliation['uploaded'][$figure] - $reconciliation['record'][$figure]) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{-- Row by row against B1/B2 (2026-11-01). --}}
        @if (($reconciliation['unmatched'] ?? []) !== [])
            <div class="small text-danger mb-1" id="working-list-unmatched"><strong>{{ __('vacancymaster.proposal.working_list.match.unmatched_heading') }}</strong>
                <ul class="mb-1">
                    @foreach ($reconciliation['unmatched'] as $row)
                        <li>{{ __('vacancymaster.proposal.working_list.match.row', ['sn' => $row['sn'], 'name' => $row['name'] ?? '—', 'message' => $row['message']]) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (($reconciliation['missing'] ?? []) !== [])
            <div class="small text-danger mb-2" id="working-list-unnamed"><strong>{{ __('vacancymaster.proposal.working_list.match.missing_heading') }}</strong>
                <ul class="mb-1">
                    @foreach ($reconciliation['missing'] as $entry)
                        <li>{{ $entry['kind'] }}: {{ $entry['name'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        {{-- Each officer's Retirement Date, calculated from the list's date of birth and
             the record's Retirement Age (2026-11-12): shown, never entered. --}}
        @if (($reconciliation['retirement_age'] ?? null) === null)
            <div class="alert alert-warning py-2 small mb-2" id="working-list-no-retirement-age">{{ __('vacancymaster.proposal.working_list.retirement.no_age') }}</div>
        @endif
        @if (($reconciliation['officers'] ?? []) !== [])
            <details class="mb-2" id="working-list-officers">
                <summary class="small text-primary">{{ __('vacancymaster.proposal.working_list.retirement.officers', ['count' => count($reconciliation['officers'])]) }}</summary>
                <div class="table-responsive mt-2">
                    <table class="table table-sm align-middle small mb-0">
                        <thead class="table-light"><tr>
                            <th>{{ __('ui.sn') }}</th>
                            <th>{{ __('dpc.employee_details.employee_id') }}</th>
                            <th>{{ __('ui.name') }}</th>
                            <th>{{ __('ui.dob') }}</th>
                            <th>{{ __('vacancymaster.proposal.working_list.retirement.date') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($reconciliation['officers'] as $officer)
                                <tr data-officer-retires="{{ $officer['retires']?->toDateString() ?? '' }}">
                                    <td>{{ $officer['sn'] }}</td>
                                    <td>{{ $officer['employee_number'] ?? '—' }}</td>
                                    <td>{{ $officer['name'] }}</td>
                                    <td>{{ $officer['dob']?->format('d-m-Y') ?? '—' }}</td>
                                    <td>{{ $officer['retires']?->format('d-m-Y') ?? __('vacancymaster.proposal.working_list.retirement.not_calculated') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (($reconciliation['retirement_age'] ?? null) !== null)
                    <div class="form-text">{{ __('vacancymaster.proposal.working_list.retirement.help', ['age' => $reconciliation['retirement_age']]) }}</div>
                @endif
            </details>
        @endif
        @if ($reconciliation['matches'])
            <div class="alert alert-success py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.matches') }}</div>
        @else
            <div class="alert alert-danger py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.differs') }}</div>
        @endif
    @endif
</div>
