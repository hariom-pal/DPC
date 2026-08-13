{{-- Working Officers & Vacancies (PPT S16, reviewed at S23). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">Working Officers & Vacancies</h2>
                        @if ($proposal->status->isEditable() && $isAO)
                            <a href="{{ route('dpc-proposals.working-officers', $proposal) }}" class="btn btn-outline-primary btn-sm">Manage</a>
                        @endif
                    </div>

                    <p class="small mb-1 mt-2">Sanctioned Post Count: <strong>{{ $proposal->sanctioned_post_count ?? '—' }}</strong></p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead><tr><th>Vacancy Type</th><th>Reservation Category</th><th>Count</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->vacancies as $vacancy)
                                    <tr>
                                        <td>{{ $vacancy->vacancyType->name_en }}</td>
                                        <td>{{ $vacancy->reservationCategory->code }}</td>
                                        <td>{{ $vacancy->count }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-2">No vacancy breakdown yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>SN</th><th>Name</th><th>DOB</th><th>Category</th><th>Roster</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->workingOfficers as $officer)
                                    <tr>
                                        <td>{{ $officer->sn }}</td>
                                        <td>{{ $officer->name }}</td>
                                        <td>{{ $officer->dob->format('d-m-Y') }}</td>
                                        <td>{{ $officer->reservationCategory->code }}</td>
                                        <td>{{ $officer->roster_position ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-2">No working officers yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

