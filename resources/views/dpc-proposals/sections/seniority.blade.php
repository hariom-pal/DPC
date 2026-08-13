{{-- Seniority List (PPT S17, reviewed at S24). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">Seniority List</h2>
                        @if ($proposal->status->isEditable() && $isAO)
                            <a href="{{ route('dpc-proposals.seniority-list', $proposal) }}" class="btn btn-outline-primary btn-sm">Manage</a>
                        @endif
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>S.N.</th><th>Employee No.</th><th>Name</th><th>DOB</th><th>Category</th><th>Feeder Post</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->seniorityEntries as $entry)
                                    <tr>
                                        <td>{{ $entry->seniority_number }}</td>
                                        <td>{{ $entry->employee_number ?? '—' }}</td>
                                        <td>{{ $entry->name }}</td>
                                        <td>{{ $entry->dob->format('d-m-Y') }}</td>
                                        <td>{{ $entry->reservationCategory->code }}</td>
                                        <td>{{ $entry->feeder_post ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-2">No seniority entries yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

