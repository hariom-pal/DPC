@extends('layouts.app')

@section('title', $row->name)

@section('content')
    {{-- One candidate's whole record, and each member's Agree on it (2026-11-18: a
         candidate is never called before a DPC). **No marks**: the marking scheme is
         not settled, and a score box invented now would be inventing it. --}}
    @php($mine = $reviews->firstWhere('user_id', auth()->id()))

    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')


            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-0">{{ $row->name }}</h1>
                    <div class="text-muted small">
                        {{ __('ui.sn') }} {{ $row->sn }} · {{ $row->employee_number ?? '—' }} · {{ $proposal->citation() }}
                    </div>
                </div>
            </div>

            {{-- This member's Agree or Disagree on this candidate (2026-11-25), and — for the
                 DPC Chairman alone — every member's Disagree with its remark. --}}
            <div class="card border-0 shadow-sm mb-3" id="candidate-ok">
                <div class="card-body">
                    @error('ok')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                    @error('remarks')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                    @if ($mine === null && $agreeRefusal)
                        <span class="small text-body-secondary" id="ok-unavailable">{{ $agreeRefusal }}</span>
                    @elseif ($mine === null)
                        <span class="small text-body-secondary">{{ __('proceeding.sitting.agree_on_list') }}</span>
                        <a href="{{ route('sitting.show', $proposal) }}#member-agree" class="small">{{ __('proceeding.sitting.candidates') }}</a>
                    @else
                        @include('proceedings.sitting._agree-state', ['review' => $mine, 'compact' => false])
                    @endif
                    @if ($disagreements->isNotEmpty())
                        <div class="mt-2" id="candidate-disagreements">
                            <div class="small fw-semibold">{{ __('proceeding.sitting.disagreements_heading') }}</div>
                            @foreach ($disagreements as $disagreement)
                                <div class="small text-danger" data-disagreement="{{ $row->id }}"><strong>{{ $disagreement->user?->name ?? '—' }}</strong> ({{ $disagreement->disagreed_at->format('d-m-Y H:i') }}): <span class="text-break" style="white-space: pre-line">{{ $disagreement->disagree_remarks }}</span></div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- The DPC Chairman edits the record themselves at the sitting (2026-11-18). --}}
            @can('editAtSitting', $proposal)
                <div class="card border-warning shadow-sm mb-3" id="chairman-edit">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <a href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}" class="btn btn-warning" id="chairman-edit-link">{{ __('proceeding.sitting.edit_record') }}</a>
                        <span class="small text-body-secondary">{{ __('proceeding.sitting.edit_record_help') }}</span>
                    </div>
                </div>
            @endcan

            {{-- The record itself. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('proceeding.sitting.record') }}</h2>
                    {{-- Every field the Evaluation Chart carries, in two columns so a
                         committee can read it at a glance rather than scrolling
                         (business instruction, 2026-09-07). --}}
                    <div class="row">
                        <div class="col-md-6">
                            <dl class="row mb-0 small">
                                <dt class="col-5">{{ __('ui.sn') }}</dt><dd class="col-7">{{ $row->sn }}</dd>
                                <dt class="col-5">{{ __('ui.employee_no') }}</dt><dd class="col-7">{{ $row->employee_number ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.dob') }}</dt><dd class="col-7">{{ $row->dob?->format('d-m-Y') ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.mobile') }}</dt><dd class="col-7">{{ $row->mobile ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.qualification') }}</dt><dd class="col-7">{{ $row->qualification ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.category') }}</dt><dd class="col-7">{{ $row->reservationCategory->code ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.seat_category') }}</dt>
                                <dd class="col-7">
                                    {{ $row->seatReservationCategory->code ?? __('ui.not_recorded') }}
                                    @if ($row->seatReservationCategory && $row->seat_reservation_category_id !== $row->reservation_category_id)
                                        <span class="badge text-bg-light">{{ __('proceeding.sitting.other_category_seat') }}</span>
                                    @endif
                                </dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <dl class="row mb-0 small">
                                <dt class="col-5">{{ __('ui.promotion_from_post') }}</dt><dd class="col-7">{{ $row->promotion_from_post ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.promotion_to_post') }}</dt><dd class="col-7">{{ $row->promotion_to_post ?? '—' }}</dd>
                                <dt class="col-5">{{ __('proceeding.sitting.joined_service') }}</dt><dd class="col-7">{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</dd>
                                <dt class="col-5">{{ __('proceeding.sitting.posted_on_feeder') }}</dt><dd class="col-7">{{ $row->date_of_posting_on_promotion_from_post?->format('d-m-Y') ?? '—' }}</dd>
                                <dt class="col-5">{{ __('proceeding.sitting.posted_on_promotion_post') }}</dt><dd class="col-7">{{ $row->date_of_posting_on_promotion_to_post?->format('d-m-Y') ?? '—' }}</dd>
                                <dt class="col-5">{{ __('proceeding.sitting.tribal_years') }}</dt><dd class="col-7">{{ $row->tribal_area_service_years ?? '—' }}</dd>
                                <dt class="col-5">{{ __('ui.note') }}</dt><dd class="col-7">{{ $row->note ?: '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The chart itself: every year, and all four things recorded against
                 it (business instruction, 2026-09-07).

                 **Blank is not the same as N.** A cell the department never filled
                 reads "—", and one recorded as *not given* reads "No". A committee
                 that cannot tell those apart cannot tell an incomplete record from a
                 defaulting officer, which is half of what it is there to judge. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('proceeding.sitting.chart') }}</h2>

                    @if ($chartYears === [])
                        <p class="small text-body-secondary mb-0">{{ __('proceeding.sitting.chart_empty') }}</p>
                    @else
                        @php($flag = fn (?bool $value) => $value === null ? '—' : ($value ? __('ui.yes') : __('ui.no')))
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('ui.year') }}</th>
                                        <th>{{ __('proceeding.sitting.cr_grade') }}</th>
                                        <th>{{ __('proceeding.sitting.loyalty') }}</th>
                                        <th>{{ __('proceeding.sitting.property') }}</th>
                                        <th>{{ __('proceeding.sitting.tribal_area') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($chartYears as $year)
                                        <tr>
                                            <td>{{ $year['year'] }}</td>
                                            <td>
                                                @if ($year['grade'])
                                                    <strong class="fs-6">{{ $year['grade'] }}</strong>
                                                @else
                                                    <span class="text-body-secondary">—</span>
                                                @endif
                                            </td>
                                            <td>{{ $flag($year['loyalty']) }}</td>
                                            <td>{{ $flag($year['property']) }}</td>
                                            <td>{{ $flag($year['tribal_area']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @include('dpc-proposals.employees.partials.chairman-grade-changes', ['changes' => $gradeChanges])

                    {{-- Why a year is blank, where the department said so. --}}
                    @if ($row->recordGaps->isNotEmpty())
                        <h3 class="h6 mt-3">{{ __('proceeding.sitting.gaps') }}</h3>
                        <ul class="small mb-0">
                            @foreach ($row->recordGaps as $gap)
                                <li>
                                    <strong>{{ strtoupper($gap->kind) }} {{ $gap->year }}</strong> —
                                    {{ $gap->reason?->name_en ?? '—' }}@if ($gap->remarks): {{ $gap->remarks }}@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- What the engine made of them, where the DPC has been calculated. It
                 is what the committee is deliberating on. --}}
            @if ($result)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6">{{ __('proceeding.sitting.result') }}</h2>
                        @php($breakdown = is_array($result->score_breakdown) ? $result->score_breakdown : [])
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">{{ __('proceeding.sitting.score') }}</dt>
                            <dd class="col-sm-8"><strong class="fs-6">{{ $breakdown['total'] ?? '—' }}</strong></dd>

                            <dt class="col-sm-4">{{ __('proceeding.sitting.eligible') }}</dt>
                            <dd class="col-sm-8">
                                @if ($result->is_eligible)
                                    <span class="badge text-bg-success">{{ __('ui.yes') }}</span>
                                @else
                                    <span class="badge text-bg-danger">{{ __('ui.no') }}</span>
                                @endif
                                @if ($result->is_super_seat)
                                    <span class="badge text-bg-primary">{{ __('proceeding.sitting.super_seat') }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4">{{ __('proceeding.sitting.list') }}</dt>
                            <dd class="col-sm-8">{{ $result->list_type?->value ?? '—' }}@if ($result->list_position) · {{ $result->list_position }}@endif</dd>

                            <dt class="col-sm-4">{{ __('proceeding.sitting.remark') }}</dt>
                            <dd class="col-sm-8">{{ $result->remark ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>
            @endif

            <a href="{{ route('sitting.show', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('proceeding.sitting.back_to_candidates') }}</a>
        </div>
    </div>
@endsection
