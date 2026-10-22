@extends('layouts.app')

@section('title', 'DPC ' . ($proposal->dpc_code ?? 'Proposal'))

@php($isAO = auth()->user()->hasRole(\App\Enums\Role::AuthorizedOfficer->value))
@php($isAH = auth()->user()->hasRole(\App\Enums\Role::AdministrativeHead->value))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')
            @include('dpc-proposals.sections._candidate-accounts')

            {{-- Suggestion 9. Stated, not enforced: the proposal keeps the rule that
                 sanctioned it, and whether a superseded rule should block submission
                 is a business question. --}}
            @if ($ruleSuperseded)
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    @include('partials.icon', ['name' => 'bell', 'size' => 20])
                    <div>
                        <strong class="d-block mb-1">{{ __('dpc.show.rule_superseded_heading') }}</strong>
                        {{ __('dpc.show.rule_superseded') }}
                    </div>
                </div>
            @endif

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">{{ $proposal->citation() }}</h1>
                <span class="badge {{ $proposal->status->badgeClass() }} fs-6">{{ $proposal->status->label() }}</span>
            </div>

            @if ($proposal->status->value === 'returned' && $proposal->return_remarks)
                <div class="alert alert-warning"><strong>{{ __('ui.returned') }}</strong> {{ $proposal->return_remarks }}</div>
            @endif

            {{-- Why the five sections below are read-only, stated once and in
                 full (UAT, 2026-08-23). The per-section badges say *that* a
                 section is locked; this says *why*, and names what unlocks it.
                 Without it a correct refusal read as a broken upload — which is
                 how the Seniority List upload came to be reported as a defect. --}}
            @if ($proposal->status->lockReason() !== null)
                <div class="alert alert-secondary d-flex gap-2 align-items-start">
                    <span class="badge text-bg-secondary flex-shrink-0">{{ __('ui.locked') }}</span>
                    <div>
                        {{ $proposal->status->lockReason() }}
                        @if ($proposal->status->value === 'submitted' && $isAO)
                            <div class="small text-muted mt-1">
{!! __('dpc.show.submitted_note', [
                                    'at' => $proposal->submitted_at
                                        ? __('ui.on_datetime', ['at' => $proposal->submitted_at->format('d-m-Y H:i')])
                                        : '',
                                ]) !!}
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($proposal->status->isEditable())
                {{-- Final workflow validation (M4 Phase 7): the S14→S18 sequence
                     must be finished before the AH is asked to review and sign. --}}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6">{{ __('dpc.show.readiness_for_submission') }}</h2>
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
                                        <a href="{{ route($section['route'], $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('dpc.show.complete') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if ($proposal->status->value === 'submitted' && $isAH)
                <div class="alert alert-info py-2">
{{ __('dpc.show.awaiting_your_approval') }}
                    <a href="{{ route('dpc-proposals.review', [$proposal, 1]) }}" class="alert-link">{{ __('dpc.show.start_the_section_by_section_review') }}</a>
                </div>
            @endif

            @include('dpc-proposals.sections.post-selection')
            @include('dpc-proposals.sections.committee')
            @include('dpc-proposals.sections.working-officers')
            @include('dpc-proposals.sections.seniority')
            @include('dpc-proposals.sections.evaluation')
            @include('dpc-proposals.sections.verification')

            {{-- Rule 4.3: the promotion order, once the CGPSC has approved the result. --}}
            <div class="card border-0 shadow-sm mb-3" id="section-promotion-order">
                <div class="card-body d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <h2 class="h6 mb-1">{{ __('dpc.promotion_orders.title') }}</h2>
                        <div class="small text-muted">{{ __('dpc.promotion_orders.section_lead') }}</div>
                    </div>
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('dpc-proposals.promotion-orders.index', $proposal) }}">{{ __('dpc.promotion_orders.open') }}</a>
                </div>
            </div>

            {{-- M5: CALCULATE. The only addition this milestone makes to an M4
                 screen — presentation only, changing no M4 logic. A calculation
                 writes separate immutable evidence and never touches the
                 proposal, so it stays available after submission, which is when
                 the Commission actually needs it. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h2 class="h6 mb-0">{{ __('dpc.show.eligibility_calculation') }}</h2>
                            <p class="text-muted small mb-0">
                                {{ __('dpc.show.scores_the_evaluation_chart_and_produces_the_mai') }}
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('dpc-proposals.calculations.index', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.history') }}</a>
                            @if ($proposal->evaluationRows->isNotEmpty())
                                <form method="POST" action="{{ route('dpc-proposals.calculate', $proposal) }}">
                                    @csrf<button class="btn btn-primary btn-sm">{{ __('ui.calculate') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @error('calculate')<div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>@enderror

                    @if ($proposal->evaluationRows->isEmpty())
                        <p class="text-muted small mb-0 mt-2">
                            {{ __('dpc.show.the_evaluation_chart_is_empty_so_there_is_nothin') }}
                        </p>
                    @endif
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2">
                    @if ($proposal->status->isEditable() && $isAO)
                        <a href="{{ route('dpc-proposals.edit', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                        <form method="POST" action="{{ route('dpc-proposals.submit', $proposal) }}">@csrf<button class="btn btn-success btn-sm">{{ __('ui.submit_for_approval') }}</button></form>
                        <form method="POST" action="{{ route('dpc-proposals.cancel', $proposal) }}" data-confirm="{{ __('ui.cancel_this_proposal') }}">@csrf<button class="btn btn-outline-danger btn-sm">{{ __('ui.cancel') }}</button></form>
                    @endif

                    @if ($proposal->status->value === 'submitted' && $isAH)
                        <form method="POST" action="{{ route('dpc-proposals.approve', $proposal) }}" data-confirm="{{ __('ui.approve_digitally_sign_and_forward_this_proposal') }}">@csrf<button class="btn btn-success btn-sm">{{ __('ui.approve_sign_amp_forward_to_cgpsc') }}</button></form>
                    @endif

                    @if ($proposal->status->isTerminal())
                        <span class="text-muted small align-self-center">{{ __('dpc.show.no_further_actions_available_in_this_state') }}</span>
                    @endif
                </div>

                @if ($proposal->status->value === 'submitted' && $isAH)
                    <div class="card-body border-top">
                        <form method="POST" action="{{ route('dpc-proposals.return', $proposal) }}" class="row g-2">
                            @csrf
                            <div class="col-md-9">
                                <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" name="return_remarks" placeholder="{{ __('ui.remarks_required_to_return') }}">
                                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">{{ __('ui.return_for_correction') }}</button></div>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Transition history --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    {{-- Revision history (change item 18). The DPC Number is the same
                         on every row; what changes is which attempt is being read, and
                         why the previous one came back. Rows are appended and never
                         edited — `DpcProposalRevision` refuses an update to any
                         revision but the current one. --}}
                    <h2 class="h6">{{ __('dpc.show.revision_history') }}</h2>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('dpc.show.rev') }}</th><th>{{ __('dpc.show.submitted') }}</th><th>{{ __('ui.by') }}</th>
                                    <th>{{ __('dpc.show.returned') }}</th><th>{{ __('ui.by') }}</th><th>{{ __('ui.reason') }}</th><th>{{ __('ui.status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($proposal->revisions as $revision)
                                    <tr class="{{ $revision->revision_number === $proposal->revision_number ? 'table-active' : '' }}">
                                        <td>{{ $revision->revision_number }}</td>
                                        <td>{{ $revision->submitted_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                        <td>{{ $revision->submitter?->name ?? '—' }}</td>
                                        <td>{{ $revision->returned_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                        <td>{{ $revision->returner?->name ?? '—' }}</td>
                                        <td class="small">{{ $revision->return_reason ?? '—' }}</td>
                                        <td><span class="badge {{ $revision->status->badgeClass() }}">{{ $revision->status->label() }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-3">{{ __('dpc.show.no_revisions_recorded') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h2 class="h6">{{ __('ui.workflow_history') }}</h2>
                    <ul class="list-group list-group-flush">
                        @forelse ($transitions as $t)
                            <li class="list-group-item px-0">
                                <span class="badge text-bg-light">{{ $t->event }}</span>
                                {{ $t->from_state }} → {{ $t->to_state }}
                                {!! __('ui.by_actor_on_span', ['actor' => e($t->actor?->name), 'at' => e($t->occurred_at->format('d-m-Y H:i'))]) !!}
                                @if ($t->signature_id){!! __('ui.signed_hash_badge', ['id' => $t->signature_id]) !!}@endif
                                @if ($t->remarks)<div class="small text-muted">{{ $t->remarks }}</div>@endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ __('ui.no_transitions_yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('dpc-proposals.index') }}" class="btn btn-link mt-2">{{ __('ui.back_to_list') }}</a>
        </div>
    </div>
@endsection
