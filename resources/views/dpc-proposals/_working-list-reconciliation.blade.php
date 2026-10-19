{{--
    Step 1 of a Vacancy Management proposal: the AO's Working Officers List against the
    finalized record. Figures that differ are marked; the Seniority List stays closed
    until all four agree.
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
                    @foreach (['promotion', 'direct', 'deputation', 'total'] as $figure)
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
        @if ($reconciliation['matches'])
            <div class="alert alert-success py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.matches') }}</div>
        @else
            <div class="alert alert-danger py-2 small mb-0">{{ __('vacancymaster.proposal.working_list.differs') }}</div>
        @endif
    @endif
</div>
