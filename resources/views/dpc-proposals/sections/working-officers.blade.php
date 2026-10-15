{{-- Working Officers & Vacancies (PPT S16, reviewed at S23). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ __('ui.working_officers_vacancies') }}</h2>
                        @include('dpc-proposals.sections._action', ['manageRoute' => 'dpc-proposals.working-officers'])
                    </div>

                    {!! __('dpc.sections.working_officers.sanctioned_post_count', ['count' => $proposal->sanctioned_post_count ?? '—']) !!}

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead><tr><th>{{ __('ui.vacancy_type') }}</th><th>{{ __('ui.reservation_category') }}</th><th>{{ __('ui.count') }}</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->vacancies as $vacancy)
                                    <tr>
                                        <td>{{ $vacancy->vacancyType->name_en }}</td>
                                        <td>{{ $vacancy->reservationCategory->code }}</td>
                                        <td>{{ $vacancy->count }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-2">{{ __('dpc.sections.working_officers.no_vacancy_breakdown_yet') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('ui.sn') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.dob') }}</th><th>{{ __('ui.category') }}</th><th>{{ __('dpc.sections.working_officers.roster') }}</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->workingOfficers as $officer)
                                    {{-- A row may be a vacant post since the business
                                         directive of 2026-09-02, and a vacant post has
                                         no name and no date of birth. --}}
                                    <tr @class(['table-light text-body-secondary' => $officer->isVacant()])>
                                        <td>{{ $officer->sn }}</td>
                                        <td>{{ $officer->displayName() }}</td>
                                        <td>{{ $officer->dob?->format('d-m-Y') ?? '—' }}</td>
                                        <td>{{ $officer->reservationCategory->code }}</td>
                                        <td>{{ $officer->roster_position ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-2">{{ __('dpc.sections.working_officers.no_working_officers_yet') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

