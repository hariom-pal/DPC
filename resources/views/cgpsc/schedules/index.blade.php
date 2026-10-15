@extends('layouts.app')

@section('title', __('cgpsc.schedules.index.acceptance_scheduling_of_dpc'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.schedules.index.acceptance_of_dpc_and_scheduling') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.schedules.index.dpcs_allotted_to_you_accept_a_dpc_then_set_the_d') }}
            </p>

            @error('scheduling')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('cgpsc.schedules.index.allotted_to_you') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.sn') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.dpc_p_no') }}</th><th>{{ __('ui.proposal_date') }}</th>
                                    <th>{{ __('ui.post_from') }}</th><th>{{ __('ui.post_to') }}</th><th>{{ __('cgpsc.schedules.index.complete_by') }}</th>
                                    <th style="width:24rem">{{ __('ui.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($allotments as $index => $allotment)
                                    @php($proposal = $allotment->proposal)
                                    @php($schedule = $schedules[$allotment->id] ?? null)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->promotionFromPost->name }}</td>
                                        <td>{{ $proposal->promotionToPost->name }}</td>
                                        {{-- The deadline, not the withdrawn "suggested DPC date":
                                             it is the only date on an allotment that governs
                                             anything, and it is what the allottee schedules against. --}}
                                        <td>{{ $allotment->complete_by?->format('d/m/Y') ?? '—' }}</td>
                                        <td>
                                            <span class="badge {{ $allotment->status->badgeClass() }}">{{ $allotment->status->label() }}</span>

                                            @if (! $isAllottee($allotment))
                                                {!! __('cgpsc.schedules.index.allotted_to', ['name' => e($allotment->allottee->name)]) !!}
                                            @elseif ($allotment->status->value === 'pending')
                                                {{-- Accept, or decline with a reason (business instruction,
                                                     2026-09-06). Declining returns the DPC to the CGPSC
                                                     Chairman, who allots it to somebody else — the reason is
                                                     what they allot from, so it is required. --}}
                                                <div class="d-flex gap-2 mt-1">
                                                    <form method="POST" action="{{ route('cgpsc.schedules.accept', $proposal) }}">
                                                        @csrf<button class="btn btn-primary btn-sm">{{ __('cgpsc.schedules.index.accept_dpc') }}</button>
                                                    </form>
                                                </div>
                                                <details class="mt-1">
                                                    <summary class="small text-danger" style="cursor:pointer">{{ __('cgpsc.schedules.index.decline_dpc') }}</summary>
                                                    <form method="POST" action="{{ route('cgpsc.schedules.decline', $proposal) }}" class="row g-1 mt-1">
                                                        @csrf
                                                        <div class="col-12">
                                                            <input type="text" name="decline_reason" class="form-control form-control-sm"
                                                                   placeholder="{{ __('cgpsc.schedules.index.decline_reason_placeholder') }}" required>
                                                        </div>
                                                        <div class="col-12">
                                                            <button class="btn btn-outline-danger btn-sm w-100">{{ __('cgpsc.schedules.index.decline_dpc') }}</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            @elseif ($allotment->status->value === 'accepted')
                                                {{-- One control, not a date box beside a time box
                                                     (business instruction, 2026-09-06). `max` is the
                                                     completion deadline the CGPSC Chairman set, so the
                                                     rule that a sitting falls on or before it is visible
                                                     in the picker rather than only after a redirect.
                                                     `ScheduleDpcRequest` splits it back into the two
                                                     fields the record keeps, and enforces the same
                                                     bound. --}}
                                                <form method="POST" action="{{ route('cgpsc.schedules.store', $proposal) }}" class="row g-1 mt-1">
                                                    @csrf
                                                    <div class="col-8">
                                                        <label class="form-label small mb-0">{{ __('cgpsc.schedules.index.dpc_date_and_time') }}</label>
                                                        <input type="datetime-local" name="dpc_at" class="form-control form-control-sm"
                                                               @if ($allotment->complete_by) max="{{ $allotment->complete_by->format('Y-m-d') }}T23:59" @endif
                                                               required>
                                                        @if ($allotment->complete_by)
                                                            <div class="form-text small">
                                                                {{ __('cgpsc.schedules.index.on_or_before', ['date' => $allotment->complete_by->format('d/m/Y')]) }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <div class="col-4 align-self-end"><button class="btn btn-success btn-sm w-100">{{ __('cgpsc.schedules.index.schedule') }}</button></div>
                                                </form>
                                            @elseif ($schedule !== null)
                                                @php($previous = $previousSchedules[$schedule->id] ?? null)
                                                <div class="small mt-1">
{!! __('cgpsc.schedules.index.date_at_time', [
                                                        'date' => $schedule->dpc_date->format('d/m/Y'),
                                                        'time' => \Illuminate\Support\Carbon::parse($schedule->dpc_time)->format('h:i A'),
                                                    ]) !!}
                                                    @if ($previous !== null)
                                                        <span class="text-muted">
{!! __('cgpsc.schedules.index.was', [
                                                            'date' => $previous->dpc_date->format('d/m/Y'),
                                                            'time' => \Illuminate\Support\Carbon::parse($previous->dpc_time)->format('h:i A'),
                                                        ]) !!}
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($isAllottee($allotment))
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer">{{ __('cgpsc.schedules.index.reschedule') }}</summary>
                                                        <form method="POST" action="{{ route('cgpsc.schedules.reschedule', $proposal) }}" class="row g-1 mt-1">
                                                            @csrf
                                                            <div class="col-8">
                                                                <input type="datetime-local" name="dpc_at" class="form-control form-control-sm"
                                                                       @if ($allotment->complete_by) max="{{ $allotment->complete_by->format('Y-m-d') }}T23:59" @endif
                                                                       required>
                                                            </div>
                                                            <div class="col-4"><button class="btn btn-outline-warning btn-sm w-100">{{ __('cgpsc.schedules.index.move') }}</button></div>
                                                            <div class="col-12">
                                                                <input type="text" name="reschedule_reason" class="form-control form-control-sm"
                                                                       placeholder="{{ __('cgpsc.schedules.index.reason_for_re_scheduling_required') }}" required>
                                                            </div>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif

                                            <div class="mt-1">
                                                <a href="{{ route('cgpsc.schedules.history', $proposal) }}" class="small">{{ __('ui.history') }}</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">
                                        {{ __('cgpsc.schedules.index.no_dpc_has_been_allotted_to_you_yet') }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- S30 — the scheduling report --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">{{ __('cgpsc.schedules.index.scheduling_report') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.sn') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.dpc_p_no') }}</th><th>{{ __('ui.proposal_date') }}</th>
                                    <th>{{ __('ui.post_from') }}</th><th>{{ __('ui.post_to') }}</th><th>{{ __('cgpsc.schedules.index.scheduling') }}</th><th>{{ __('cgpsc.schedules.index.dpc_date') }}</th><th>{{ __('cgpsc.schedules.index.dpc_time') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($reported = 0)
                                @foreach ($allotments as $allotment)
                                    @php($schedule = $schedules[$allotment->id] ?? null)
                                    @continue($schedule === null)
                                    @php($reported++)
                                    @php($proposal = $allotment->proposal)
                                    <tr>
                                        <td>{{ $reported }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->promotionFromPost->name }}</td>
                                        <td>{{ $proposal->promotionToPost->name }}</td>
                                        <td><span class="badge text-bg-success">{{ __('cgpsc.schedules.index.done') }}</span></td>
                                        @php($previous = $previousSchedules[$schedule->id] ?? null)
                                        <td>
                                            {{ $schedule->dpc_date->format('d/m/Y') }}
                                            @if ($previous !== null)
                                                <br><s class="text-muted small">{{ $previous->dpc_date->format('d/m/Y') }}</s>
                                            @endif
                                        </td>
                                        <td>
                                            {{ \Illuminate\Support\Carbon::parse($schedule->dpc_time)->format('h:i A') }}
                                            @if ($previous !== null)
                                                <br><s class="text-muted small">{{ \Illuminate\Support\Carbon::parse($previous->dpc_time)->format('h:i A') }}</s>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach

                                @if ($reported === 0)
                                    <tr><td colspan="9" class="text-center text-muted py-4">{{ __('cgpsc.schedules.index.no_dpc_has_been_scheduled_yet') }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
