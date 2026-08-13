@extends('layouts.app')

@section('title', 'DPC Proposal — ' . ($proposal->dpc_code ?? 'Draft'))

@php($isAO = auth()->user()->hasRole(\App\Enums\Role::AuthorizedOfficer->value))
@php($isAH = auth()->user()->hasRole(\App\Enums\Role::AdministrativeHead->value))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">DPC Proposal @if ($proposal->dpc_code)— {{ $proposal->dpc_code }}@endif</h1>
                <span class="badge {{ $proposal->status->badgeClass() }} fs-6">{{ $proposal->status->label() }}</span>
            </div>

            @if ($proposal->status->value === 'returned' && $proposal->return_remarks)
                <div class="alert alert-warning"><strong>Returned:</strong> {{ $proposal->return_remarks }}</div>
            @endif

            @if ($proposal->status->isEditable())
                {{-- Final workflow validation (M4 Phase 7): the S14→S18 sequence
                     must be finished before the AH is asked to review and sign. --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6">Readiness for Submission</h2>
                        <ul class="list-group list-group-flush">
                            @foreach ($checklist as $section)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                                    <span>
                                        @if ($section['complete'])
                                            <span class="badge text-bg-success">✓</span>
                                        @else
                                            <span class="badge text-bg-warning">!</span>
                                        @endif
                                        <strong>{{ $section['label'] }}</strong>
                                        <span class="text-muted small">({{ $section['screen'] }})</span>
                                        <div class="small text-muted ms-4">{{ $section['detail'] }}</div>
                                    </span>
                                    @if (! $section['complete'] && $isAO)
                                        <a href="{{ route($section['route'], $proposal) }}" class="btn btn-outline-primary btn-sm">Complete</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if ($proposal->status->value === 'submitted' && $isAH)
                <div class="alert alert-info py-2">
                    This proposal is awaiting your approval.
                    <a href="{{ route('dpc-proposals.review', [$proposal, 1]) }}" class="alert-link">Start the section-by-section review →</a>
                </div>
            @endif

            @include('dpc-proposals.sections.post-selection')
            @include('dpc-proposals.sections.committee')
            @include('dpc-proposals.sections.working-officers')
            @include('dpc-proposals.sections.seniority')
            @include('dpc-proposals.sections.evaluation')

            {{-- M5: CALCULATE. The only addition this milestone makes to an M4
                 screen — presentation only, changing no M4 logic. A calculation
                 writes separate immutable evidence and never touches the
                 proposal, so it stays available after submission, which is when
                 the Commission actually needs it. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h2 class="h6 mb-0">Eligibility Calculation</h2>
                            <p class="text-muted small mb-0">
                                Scores the evaluation chart and produces the Main and Waiting lists.
                                Each run is kept permanently — recalculating never replaces an earlier result.
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('dpc-proposals.calculations.index', $proposal) }}" class="btn btn-outline-secondary btn-sm">History</a>
                            @if ($proposal->evaluationRows->isNotEmpty())
                                <form method="POST" action="{{ route('dpc-proposals.calculate', $proposal) }}">
                                    @csrf<button class="btn btn-primary btn-sm">CALCULATE</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @error('calculate')<div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>@enderror

                    @if ($proposal->evaluationRows->isEmpty())
                        <p class="text-muted small mb-0 mt-2">
                            The evaluation chart is empty, so there is nothing to calculate yet.
                        </p>
                    @endif
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2">
                    @if ($proposal->status->isEditable() && $isAO)
                        <a href="{{ route('dpc-proposals.edit', $proposal) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                        <form method="POST" action="{{ route('dpc-proposals.submit', $proposal) }}">@csrf<button class="btn btn-success btn-sm">Submit for Approval</button></form>
                        <form method="POST" action="{{ route('dpc-proposals.cancel', $proposal) }}" onsubmit="return confirm('Cancel this proposal?');">@csrf<button class="btn btn-outline-danger btn-sm">Cancel</button></form>
                    @endif

                    @if ($proposal->status->value === 'submitted' && $isAH)
                        <form method="POST" action="{{ route('dpc-proposals.approve', $proposal) }}" onsubmit="return confirm('Approve, digitally sign, and forward this proposal to CGPSC?');">@csrf<button class="btn btn-success btn-sm">Approve, Sign &amp; Forward to CGPSC</button></form>
                    @endif

                    @if ($proposal->status->isTerminal())
                        <span class="text-muted small align-self-center">No further actions available in this state.</span>
                    @endif
                </div>

                @if ($proposal->status->value === 'submitted' && $isAH)
                    <div class="card-body border-top">
                        <form method="POST" action="{{ route('dpc-proposals.return', $proposal) }}" class="row g-2">
                            @csrf
                            <div class="col-md-9">
                                <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" name="return_remarks" placeholder="Remarks (required to return)">
                                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">Return for Correction</button></div>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Transition history --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Workflow History</h2>
                    <ul class="list-group list-group-flush">
                        @forelse ($transitions as $t)
                            <li class="list-group-item px-0">
                                <span class="badge text-bg-light">{{ $t->event }}</span>
                                {{ $t->from_state }} → {{ $t->to_state }}
                                <span class="text-muted small">by {{ $t->actor?->name }} on {{ $t->occurred_at->format('d-m-Y H:i') }}</span>
                                @if ($t->signature_id)<span class="badge text-bg-success">signed #{{ $t->signature_id }}</span>@endif
                                @if ($t->remarks)<div class="small text-muted">{{ $t->remarks }}</div>@endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">No transitions yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('dpc-proposals.index') }}" class="btn btn-link mt-2">← Back to list</a>
        </div>
    </div>
@endsection
