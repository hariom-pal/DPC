{{--
    Step 1 of a Vacancy Management proposal: the AO's Working Officers List against the
    finalized record. Figures that differ are marked, and so is each row that does not
    agree with B1/B2; the Seniority List stays closed until everything agrees.
    Expects: $reconciliation (ProposalVacancyPosition::reconcile()).
--}}
<div id="working-list-reconciliation" data-reconciled="{{ $reconciliation['matches'] ? 'yes' : 'no' }}">
    <h3 class="h6">{{ __('vacancymaster.proposal.working_list.heading') }}</h3>
    @if ($reconciliation['uploaded'] === null)
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
        @if ($reconciliation['matches'])
            <div class="alert alert-success py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.matches') }}</div>
        @else
            <div class="alert alert-danger py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.differs') }}</div>
        @endif
    @endif
</div>
