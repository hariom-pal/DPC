@extends('layouts.app')

@section('title', $row->name)

@section('content')
    {{-- One candidate's whole record, and the two acts the committee performs on it
         (business instruction, 2026-09-07).

         **No marks.** Business has not settled the marking scheme — *"how the
         marking is to be done we will see later"* — so this records that the
         candidate was called and that the committee concluded them, with who and
         when. A score box invented now would be inventing the scheme. --}}
    @php($call = $row->candidateCall)

    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            @error('call')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-0">{{ $row->name }}</h1>
                    <div class="text-muted small">
                        {{ __('ui.sn') }} {{ $row->sn }} · {{ $row->employee_number ?? '—' }} · {{ $proposal->citation() }}
                    </div>
                </div>
                <div class="text-end">
                    @if ($call?->isConcluded())
                        <span class="badge text-bg-success fs-6">{{ __('proceeding.sitting.concluded_badge') }}</span>
                    @elseif ($call?->isCalled())
                        <span class="badge text-bg-warning fs-6">{{ __('proceeding.sitting.called_badge') }}</span>
                    @else
                        <span class="badge text-bg-light fs-6">{{ __('proceeding.sitting.waiting_badge') }}</span>
                    @endif
                </div>
            </div>

            {{-- The two acts, before the record: this is the screen the committee is
                 working from, and what they came to do belongs at the top of it. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    @unless ($callOpen)
                        <div class="alert alert-secondary py-2 small mb-3">{{ $callReason }}</div>
                    @endunless

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <form method="POST" action="{{ route('sitting.call', [$proposal, $row]) }}" class="m-0">
                            @csrf
                            <button class="btn btn-primary" @disabled(! $callOpen)>
                                {{ $call?->isCalled() ? __('proceeding.sitting.call_again') : __('proceeding.sitting.call') }}
                            </button>
                        </form>

                        @if ($call?->isCalled())
                            <span class="small text-body-secondary">
                                {{ __('proceeding.sitting.called_by', [
                                    'name' => $call->caller?->name ?? '—',
                                    'at' => $call->called_at?->format('d-m-Y H:i'),
                                ]) }}
                            </span>
                        @endif
                    </div>

                    @if ($call?->isCalled() && ! $call->isConcluded())
                        <form method="POST" action="{{ route('sitting.conclude', [$proposal, $row]) }}" class="row g-2 mt-2">
                            @csrf
                            <div class="col-md-8">
                                <label class="form-label small mb-0" for="remarks">{{ __('proceeding.sitting.remarks') }}</label>
                                <input type="text" class="form-control form-control-sm" id="remarks" name="remarks"
                                       placeholder="{{ __('proceeding.sitting.remarks_hint') }}">
                            </div>
                            <div class="col-md-4 align-self-end">
                                <button class="btn btn-success w-100">{{ __('proceeding.sitting.conclude') }}</button>
                            </div>
                        </form>
                    @endif

                    @if ($call?->isConcluded())
                        <div class="alert alert-success py-2 small mt-3 mb-0">
                            {{ __('proceeding.sitting.concluded_by', [
                                'name' => $call->concluder?->name ?? '—',
                                'at' => $call->concluded_at?->format('d-m-Y H:i'),
                            ]) }}
                            @if ($call->remarks)
                                <div class="mt-1">{{ $call->remarks }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

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
