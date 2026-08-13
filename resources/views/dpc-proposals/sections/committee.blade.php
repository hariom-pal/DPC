{{-- DPC Committee (PPT S15, reviewed at S22). Shared by the proposal detail screen and the AH review walkthrough. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">DPC Committee</h2>
                        @if ($proposal->status->isEditable() && $isAO)
                            <a href="{{ route('dpc-proposals.committee', $proposal) }}" class="btn btn-outline-primary btn-sm">Manage Committee</a>
                        @endif
                    </div>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Name</th><th>Designation</th><th>Mobile</th><th>Email</th><th>Capacity</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->committeeMembers as $member)
                                    <tr>
                                        <td>{{ $member->name }} {!! $member->isExternal() ? '<span class="badge text-bg-light">external</span>' : '' !!}</td>
                                        <td>{{ $member->designation ?? '—' }}</td>
                                        <td>{{ $member->mobile ?? '—' }}</td>
                                        <td>{{ $member->email ?? '—' }}</td>
                                        <td><span class="badge {{ $member->capacity->value === 'chairman' ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $member->capacity->label() }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">No committee members yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

