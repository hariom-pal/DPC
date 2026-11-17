@extends('layouts.app')

@section('title', __('proceeding.sitting.candidates'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            @error('call')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <h1 class="h4 mb-1">{{ __('proceeding.sitting.candidates') }}</h1>
            <p class="text-muted small mb-2">{{ $proposal->citation() }}</p>

            {{-- The Call window. Stated here rather than only implied by a disabled
                 button: a committee that cannot call needs to know when it can. --}}
            <div class="alert {{ $callOpen ? 'alert-success' : 'alert-secondary' }} py-2 small">
                @if ($callOpen)
                    {{ __('proceeding.sitting.window_open') }}
                @else
                    {{ $callReason }}
                @endif
            </div>

            @include('dpc-proposals._next-process')

            @can('viewRecords', $proposal)
                <div class="mb-3"><a href="{{ route('dpc-proposals.tabulation-chart', $proposal) }}" class="btn btn-outline-primary btn-sm" id="sitting-tabulation-link">{{ __('dpc.tabulation.open') }}</a></div>
            @endcan

            @include('proceedings.sitting._decision')

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
                            <form method="POST" action="{{ route('dpc-proposals.calculate', $proposal) }}">
                                @csrf<button class="btn btn-primary btn-sm">{{ __('ui.calculate') }}</button>
                            </form>
                        </div>
                    </div>
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
                                <th>{{ __('proceeding.sitting.status') }}</th>
                                <th class="text-end">{{ __('ui.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($candidates as $row)
                                @php($call = $row->candidateCall)
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
                                    <td>
                                        @if ($call?->isConcluded())
                                            <span class="badge text-bg-success">{{ __('proceeding.sitting.concluded_badge') }}</span>
                                        @elseif ($call?->isCalled())
                                            <span class="badge text-bg-warning">{{ __('proceeding.sitting.called_badge') }}</span>
                                        @else
                                            <span class="badge text-bg-light">{{ __('proceeding.sitting.waiting_badge') }}</span>
                                        @endif
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
