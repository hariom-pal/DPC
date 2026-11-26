@extends('layouts.app')

@section('title', __('proceeding.sitting.candidates'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            @error('meeting')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('ok')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('remarks')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('member_view')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <h1 class="h4 mb-1">{{ __('proceeding.sitting.candidates') }}</h1>
            <p class="text-muted small mb-2">{{ $proposal->citation() }}</p>

            {{-- The sitting's meeting (2026-11-18): the DPC Chairman starts it; every
                 member joins it through the portal. Candidates are never called. --}}
            @php($meetings = app(\App\Domain\Proceeding\Services\SittingMeetingService::class))
            <div class="card border-0 shadow-sm mb-3" id="sitting-meeting">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="small">
                        @if ($meeting)
                            <span class="badge text-bg-success">{{ __('proceeding.sitting.meeting_live') }}</span>
                            {{ __('proceeding.sitting.meeting_started_by', ['name' => $meeting->initiator?->name ?? '—', 'at' => $meeting->initiated_at->format('d-m-Y H:i')]) }}
                        @else
                            {{ __('proceeding.sitting.meeting_none') }}
                        @endif
                        {{-- The day and hour every member joins (2026-11-18). --}}
                        @if ($schedule)
                            <div id="meeting-when">{{ __('proceeding.sitting.scheduled_for', ['when' => $schedule->scheduledFor()->format('d-m-Y H:i')]) }}</div>
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        @if ($meeting)
                            <a href="{{ route('sitting.meeting', $proposal) }}" target="_blank" rel="noopener" class="btn btn-success btn-sm" id="join-meeting">{{ __('proceeding.sitting.meeting_join') }}</a>
                        @endif
                        @if ($meetings->mayInitiate($proposal, auth()->user()))
                            <form method="POST" action="{{ route('sitting.meeting.initiate', $proposal) }}" class="m-0">
                                @csrf
                                <button class="btn btn-primary btn-sm" id="initiate-meeting">{{ $meeting ? __('proceeding.sitting.meeting_restart') : __('proceeding.sitting.meeting_initiate') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            {{-- 2026-11-24: the DPC Chairman opens the DPC to the committee members. --}}
            @include('proceedings.sitting._member-view')

            @include('dpc-proposals._next-process')

            @can('viewRecords', $proposal)
                <div class="mb-3"><a href="{{ route('dpc-proposals.tabulation-chart', $proposal) }}" class="btn btn-outline-primary btn-sm" id="sitting-tabulation-link">{{ __('dpc.tabulation.open') }}</a></div>
            @endcan

            @include('proceedings.sitting._decision')

            {{-- 2026-11-25: each member agrees once with the whole DPC, then disagrees on any candidate. --}}
            @if ($memberCount > 0 && in_array(auth()->id(), $meetings->requiredMemberIds($proposal), true))
                <div class="card border-0 shadow-sm mb-3" id="member-agree">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <h2 class="h6 w-100 mb-1">{{ __('proceeding.sitting.agree_heading') }}</h2>
                        @if ($awaitingAgree === [])
                            <span class="badge text-bg-success fs-6" id="agreed-badge">{{ __('proceeding.sitting.agreed_yours') }}</span>
                            <span class="small text-body-secondary">{{ __('proceeding.sitting.disagree_hint') }}</span>
                        @elseif ($agreeRefusal)
                            <span class="small text-body-secondary" id="agree-unavailable">{{ $agreeRefusal }}</span>
                        @else
                            <form method="POST" action="{{ route('sitting.agree', $proposal) }}" class="m-0">
                                @csrf
                                <button class="btn btn-success" id="record-agree">{{ __('proceeding.sitting.agree') }}</button>
                            </form>
                            <span class="small text-body-secondary">
                                {{ count($awaitingAgree) < $candidates->count() ? __('proceeding.sitting.agree_changed', ['count' => count($awaitingAgree)]) : __('proceeding.sitting.agree_hint') }}
                            </span>
                        @endif
                    </div>
                </div>
            @endif

            {{-- The eligibility calculation, the committee's alone (2026-11-16). --}}
            @can('viewCalculation', $proposal)
                <div class="card border-0 shadow-sm mb-3" id="committee-calculation">
                    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="h6 mb-0">{{ __('dpc.show.eligibility_calculation') }}</h2>
                            <p class="text-muted small mb-0">{{ __('dpc.show.scores_the_evaluation_chart_and_produces_the_mai') }}</p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('dpc-proposals.calculations.index', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.history') }}</a>
                            {{-- The DPC Chairman alone, once every member has agreed (2026-11-18). --}}
                            @can('calculate', $proposal)
                                <form method="POST" action="{{ route('dpc-proposals.calculate', $proposal) }}">
                                    @csrf<button class="btn btn-primary btn-sm" id="committee-calculate">{{ __('ui.calculate') }}</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                    @cannot('calculate', $proposal)
                        <p class="small text-muted mx-3 mb-2" id="calculate-unavailable">{{ app(\App\Domain\Proceeding\Services\CalculationGate::class)->refusal($proposal, auth()->user()) }}</p>
                    @endcannot
                    {{-- Whose Agree is still awaited, and why (2026-11-18). --}}
                    @php($pendingMembers = app(\App\Domain\Proceeding\Services\CalculationGate::class)->pendingMembers($proposal))
                    @if ($pendingMembers !== [])
                        <div class="small mx-3 mb-2" id="pending-agreements">
                            @if (collect($pendingMembers)->contains(fn ($m) => $m['user_id'] === auth()->id()))
                                <div class="text-danger fw-semibold mb-1" id="your-agree-pending">{{ __('dpc.calculation_gate.your_agree_pending') }}</div>
                            @endif
                            <span class="fw-semibold">{{ __('dpc.calculation_gate.pending_heading') }}:</span>
                            @foreach ($pendingMembers as $pendingMember)
                                <span class="badge text-bg-light border" data-pending-member="{{ $pendingMember['state'] }}">{{ $pendingMember['name'] }} — {{ __('dpc.calculation_gate.state_'.$pendingMember['state']) }}</span>
                            @endforeach
                        </div>
                    @endif
                    @error('calculate')<div class="alert alert-danger py-2 mx-3">{{ $message }}</div>@enderror
                </div>
            @endcan

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('ui.sn') }}</th>
                                <th>{{ __('ui.name') }}</th>
                                <th>{{ __('ui.category') }}</th>
                                <th>{{ __('proceeding.sitting.agree_column') }}</th>
                                <th class="text-end">{{ __('ui.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($candidates as $row)
                                @php($rowReviews = $reviews->get($row->id, collect()))
                                <tr>
                                    <td>{{ $row->sn }}</td>
                                    <td>
                                        <a href="{{ route('sitting.candidate', [$proposal, $row]) }}">{{ $row->name }}</a>
                                        <div class="small text-body-secondary">{{ $row->employee_number }}</div>
                                    </td>
                                    <td>
                                        {{ $row->reservationCategory->code ?? '—' }}
                                        @if ($row->seatReservationCategory && $row->seat_reservation_category_id !== $row->reservation_category_id)
                                            <span class="small text-body-secondary">({{ __('ui.seat_category') }}: {{ $row->seatReservationCategory->code }})</span>
                                        @endif
                                    </td>
                                    <td data-candidate-ok="{{ $row->id }}">
                                        @php($myReview = $rowReviews->firstWhere('user_id', auth()->id()))
                                        @include('proceedings.sitting._agree-state', ['review' => $myReview, 'compact' => true])
                                        <div class="small text-body-secondary">{{ __('proceeding.sitting.agreed_count', ['n' => $rowReviews->whereNull('disagreed_at')->count(), 'of' => $memberCount]) }}</div>
                                        {{-- The Disagrees and their remarks: the DPC Chairman's alone (2026-11-25). --}}
                                        @foreach ($disagreements->get($row->id, collect()) as $disagreement)
                                            <div class="small text-danger" data-disagreement="{{ $row->id }}"><strong>{{ $disagreement->user?->name ?? '—' }}:</strong> <span class="text-break" style="white-space: pre-line">{{ $disagreement->disagree_remarks }}</span></div>
                                        @endforeach
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('sitting.candidate', [$proposal, $row]) }}" class="btn btn-outline-primary btn-sm">
                                            {{ __('proceeding.sitting.open_record') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- The DPC's Chairman opens its sealed covers once the result is approved (2026-11-05). --}}
            @php($approvedProceeding = \App\Domain\Proceeding\Models\DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->where('status', \App\Domain\Proceeding\Enums\DpcProceedingStatus::ResultApproved->value)->latest('id')->first())
            @if ($approvedProceeding !== null && app(\App\Domain\Proceeding\Services\SealedCoverService::class)->isDpcChairman($proposal, auth()->user()))
                <a href="{{ route('cgpsc.proceedings.sealed-covers', $approvedProceeding) }}" class="btn btn-outline-warning btn-sm mt-3" id="sealed-covers-link">{{ __('dpc.sealed_cover.title') }}</a>
            @endif
            <a href="{{ route('sitting.index') }}" class="btn btn-outline-secondary btn-sm mt-3">{{ __('ui.back') }}</a>
        </div>
    </div>
@endsection
