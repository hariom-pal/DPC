{{--
    One candidate's grievances (2026-11-24). Each grievance shows where it stands and
    what happens next; the AH decides each (Upheld → to the AO, Not upheld → closed with
    its reason, eSigned), the AO sends the candidate's corrections back together, and
    the AH approves them with the eSign.
--}}
@php
    $row = $group->first()->participant->row;
    $participant = $group->first()->participant;
    $toDecide = $group->filter(fn ($g) => $g->status->value === 'submitted')->count();
    $withAo = $group->filter(fn ($g) => $g->status->value === 'sent_to_ao')->count();
    $toApprove = $group->filter(fn ($g) => $g->status->value === 'returned_from_ao')->count();
    $open = $toDecide + $withAo + $toApprove;
@endphp
<div class="card border-0 shadow-sm mb-3" data-candidate="{{ $row->sn }}" id="candidate-{{ $row->sn }}"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <h2 class="h6 mb-0">{{ $row->name }} · {{ $row->employee_number ?? '—' }} · S.N. {{ $row->sn }}</h2>
        <span class="badge {{ $open > 0 ? 'text-bg-warning' : 'text-bg-success' }}">{{ __('candidate.grievance.review.candidate_count', ['count' => $group->count(), 'open' => $open]) }}</span>
    </div>

    {{-- What this candidate is waiting on, in one line. --}}
    <div class="alert {{ $open > 0 ? 'alert-warning' : 'alert-success' }} py-1 px-2 small" data-candidate-next>
        @if ($toDecide > 0)
            {{ __('candidate.grievance.next.candidate_decide', ['count' => $toDecide]) }}
        @elseif ($withAo > 0)
            {{ __('candidate.grievance.next.candidate_with_ao', ['count' => $withAo]) }}
        @elseif ($toApprove > 0)
            {{ __('candidate.grievance.next.candidate_approve', ['count' => $toApprove]) }}
        @else
            {{ __('candidate.grievance.next.candidate_done') }}
        @endif
    </div>

    @foreach ($group as $grievance)
        @php
            $status = $grievance->status->value;
            $lastCorrection = $grievance->corrections->sortByDesc('id')->first();
        @endphp
        <div class="border rounded mb-2" data-grievance="{{ $grievance->uuid }}" data-status="{{ $status }}" id="grievance-{{ $grievance->uuid }}"><div class="p-2 small">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
                <div class="fw-semibold">{{ $grievance->section->label() }}@if ($grievance->related_year) · {{ $grievance->related_year }}@endif · {{ __('candidate.grievance.categories.'.$grievance->category) }}</div>
                <span class="badge {{ ['submitted' => 'text-bg-warning', 'sent_to_ao' => 'text-bg-info', 'returned_from_ao' => 'text-bg-primary', 'resolved' => 'text-bg-success'][$status] ?? 'text-bg-secondary' }}">{{ __('candidate.grievance.flow_status.'.($status === 'sent_to_ao' && $grievance->decision?->value === 'revised' ? 'sent_to_ao_revised' : $status)) }}</span>
            </div>
            <div>{{ __('candidate.grievance.review.remark') }}: {{ $grievance->remark }}</div>
            <div>{{ __('candidate.grievance.review.document') }}: <a href="{{ route('dpc-proposals.grievances.document', [$proposal, $grievance]) }}">{{ $grievance->original_name }}</a> · {{ __('candidate.grievance.review.submitted') }}: {{ $grievance->submitted_at?->format('d-m-Y H:i') }}</div>

            {{-- The history: each time it went to the AO and what came back, with the values
                 the AO changed — before → after (2026-11-25). --}}
            @php
                $changeSets = \App\Domain\CandidateVerification\Models\VerificationSnapshot::query()->where('dpc_grievance_id', $grievance->id)->orderBy('version')->get()
                    ->map(fn ($v) => (array) ($v->snapshot['changes'] ?? []))->values();
            @endphp
            @foreach ($grievance->corrections->sortBy('id')->values() as $i => $c)
                <div class="text-muted">
                    {{ __('candidate.grievance.review.to_ao_on', ['date' => $c->assigned_at?->format('d-m-Y')]) }}: {{ $c->instructions }}
                    @if ($c->submitted_at) → {{ __('candidate.grievance.review.ao_said', ['date' => $c->submitted_at->format('d-m-Y')]) }}: {{ $c->ao_remarks }}@endif
                </div>
                @if ($c->submitted_at && isset($changeSets[$i]))
                    <div class="small ms-3" data-changes>
                        <span class="fw-semibold">{{ __('candidate.grievance.review.changes') }}:</span>
                        @forelse ($changeSets[$i] as $change)
                            <div>{{ $change }}</div>
                        @empty
                            <span class="text-warning">{{ __('candidate.grievance.review.no_change') }}</span>
                        @endforelse
                    </div>
                @endif
            @endforeach

            @if ($status === 'resolved')
                <div class="mt-1" data-resolution>
                    <strong>{{ __('candidate.grievance.flow_result.'.($grievance->decision?->value ?? 'upheld')) }}</strong>
                    — {{ $grievance->resolution_remark }} ({{ $grievance->resolved_at?->format('d-m-Y') }}, {{ $grievance->resolver?->name }})
                </div>
            @else
                <div class="text-body-secondary mt-1" data-next>{{ __('candidate.grievance.next.'.$status) }}</div>
            @endif

            {{-- The AH's decision: two choices, each saying what it means and where it goes. --}}
            @if ($isAh && $actionable && $status === 'submitted')
                <div class="row g-2 mt-1">
                    <form method="POST" action="{{ route('dpc-proposals.grievances.decide', [$proposal, $grievance]) }}" class="col-md-4" data-decide="upheld">
                        @csrf
                        <input type="hidden" name="decision" value="upheld">
                        <div class="border border-success rounded p-2 h-100">
                            <div class="fw-semibold text-success">{{ __('candidate.grievance.decide.upheld_title') }}</div>
                            <div class="mb-1">{{ __('candidate.grievance.decide.upheld_means') }}</div>
                            <div class="mb-1 text-body-secondary">→ {{ __('candidate.grievance.decide.upheld_next') }}</div>
                            <label class="form-label small mb-0" for="upheld-{{ $grievance->uuid }}">{{ __('candidate.grievance.decide.upheld_field') }}</label>
                            <textarea id="upheld-{{ $grievance->uuid }}" name="text" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required></textarea>
                            <button class="btn btn-sm btn-success">{{ __('candidate.grievance.decide.upheld_button') }}</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('dpc-proposals.grievances.decide', [$proposal, $grievance]) }}" class="col-md-4" data-decide="revised">
                        @csrf
                        <input type="hidden" name="decision" value="revised">
                        <div class="border border-primary rounded p-2 h-100">
                            <div class="fw-semibold text-primary">{{ __('candidate.grievance.decide.revised_title') }}</div>
                            <div class="mb-1">{{ __('candidate.grievance.decide.revised_means') }}</div>
                            <div class="mb-1 text-body-secondary">→ {{ __('candidate.grievance.decide.revised_next') }}</div>
                            <label class="form-label small mb-0" for="revised-{{ $grievance->uuid }}">{{ __('candidate.grievance.decide.revised_field') }}</label>
                            <textarea id="revised-{{ $grievance->uuid }}" name="text" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required></textarea>
                            <button class="btn btn-sm btn-primary">{{ __('candidate.grievance.decide.revised_button') }}</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('dpc-proposals.grievances.decide', [$proposal, $grievance]) }}" class="col-md-4" data-decide="not_upheld">
                        @csrf
                        <input type="hidden" name="decision" value="not_upheld">
                        <div class="border border-danger rounded p-2 h-100">
                            <div class="fw-semibold text-danger">{{ __('candidate.grievance.decide.not_upheld_title') }}</div>
                            <div class="mb-1">{{ __('candidate.grievance.decide.not_upheld_means') }}</div>
                            <div class="mb-1 text-body-secondary">→ {{ __('candidate.grievance.decide.not_upheld_next') }}</div>
                            <label class="form-label small mb-0" for="not-upheld-{{ $grievance->uuid }}">{{ __('candidate.grievance.decide.not_upheld_field') }}</label>
                            <textarea id="not-upheld-{{ $grievance->uuid }}" name="text" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required></textarea>
                            <button class="btn btn-sm btn-outline-danger">{{ __('candidate.grievance.decide.not_upheld_button') }}</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- The AO's work on it. --}}
            @if ($isAo && $status === 'sent_to_ao' && $lastCorrection)
                <div class="border border-info rounded p-2 mt-1" data-ao-task>
                    <div class="fw-semibold">{{ __($grievance->decision?->value === 'revised' ? 'candidate.grievance.ao.task_revised' : 'candidate.grievance.ao.task') }}</div>
                    <div>{{ __('candidate.grievance.review.instructions') }}: {{ $lastCorrection->instructions }}</div>
                    <a class="btn btn-sm btn-outline-primary mt-1" href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}#section-{{ $grievance->section->employeeSection() }}">{{ __('candidate.grievance.review.open_employee') }}</a>
                </div>
            @endif

            {{-- The AH, with the AO's correction back: not right yet → back to the AO. --}}
            @if ($isAh && $actionable && $status === 'returned_from_ao')
                <form method="POST" action="{{ route('dpc-proposals.grievances.send-back', [$proposal, $grievance]) }}" class="mt-1" data-send-back>
                    @csrf
                    <label class="form-label small mb-0" for="back-{{ $grievance->uuid }}">{{ __('candidate.grievance.decide.send_back_field') }}</label>
                    <div class="d-flex gap-2 align-items-start">
                        <textarea id="back-{{ $grievance->uuid }}" name="instructions" class="form-control form-control-sm" rows="1" maxlength="2000" required></textarea>
                        <button class="btn btn-sm btn-outline-warning text-nowrap">{{ __('candidate.grievance.decide.send_back_button') }}</button>
                    </div>
                </form>
            @endif
        </div></div>
    @endforeach

    {{-- The AO sends this candidate's corrections back to the AH together. --}}
    @if ($isAo && $withAo > 0)
        <form method="POST" action="{{ route('dpc-proposals.grievances.submit-candidate', [$proposal, $participant]) }}" class="border border-info rounded p-2" data-submit-candidate>
            @csrf
            <div class="fw-semibold">{{ __('candidate.grievance.ao.submit_title', ['count' => $withAo]) }}</div>
            <div class="text-body-secondary small mb-1">→ {{ __('candidate.grievance.ao.submit_next') }}</div>
            <textarea name="ao_remarks" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required placeholder="{{ __('candidate.grievance.review.ao_remarks') }}"></textarea>
            <button class="btn btn-sm btn-primary">{{ __('candidate.grievance.ao.submit_button') }}</button>
        </form>
    @endif

    {{-- The AH approves the candidate's corrections with the eSign. --}}
    @if ($isAh && $actionable && $toApprove > 0)
        <form method="POST" action="{{ route('dpc-proposals.grievances.approve-candidate', [$proposal, $participant]) }}" class="border border-success rounded p-2" data-approve-candidate>
            @csrf
            <div class="fw-semibold">{{ __('candidate.grievance.decide.approve_title', ['count' => $toApprove]) }}</div>
            <div class="text-body-secondary small mb-1">→ {{ __('candidate.grievance.decide.approve_next') }}</div>
            <textarea name="remark" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required placeholder="{{ __('candidate.grievance.decide.approve_field') }}"></textarea>
            <button class="btn btn-sm btn-success">{{ __('candidate.grievance.decide.approve_button') }}</button>
        </form>
    @endif
</div></div>
