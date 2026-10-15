{{-- Seniority List (PPT S17, reviewed at S24). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ __('ui.seniority_list') }}</h2>
                        @include('dpc-proposals.sections._action', ['manageRoute' => 'dpc-proposals.seniority-list'])
                    </div>
                    <div class="small text-muted">{{ __('vacancymaster.proposal.seniority_reference', ['date' => $proposal->seniorityReferenceDate()->format('d-m-Y')]) }}</div>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('ui.s_n') }}</th><th>{{ __('ui.employee_no') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.dob') }}</th><th>{{ __('ui.category') }}</th><th>{{ __('ui.promotion_from_post') }}</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->seniorityEntries as $entry)
                                    <tr>
                                        <td>{{ $entry->seniority_number }}</td>
                                        <td>{{ $entry->employee_number ?? '—' }}</td>
                                        <td>{{ $entry->name }}</td>
                                        <td>{{ $entry->dob->format('d-m-Y') }}</td>
                                        <td>{{ $entry->reservationCategory->code }}</td>
                                        <td>{{ $entry->promotion_from_post ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-2">{{ __('dpc.sections.seniority.no_seniority_entries_yet') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

