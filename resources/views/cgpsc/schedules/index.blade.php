@extends('layouts.app')

@section('title', 'Acceptance & Scheduling of DPC')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Acceptance of DPC and Scheduling</h1>
            <p class="text-muted small mb-3">
                DPCs allotted to you. Accept a DPC, then set the date and time it will be held.
                The default DPC date shown is the Chairman's guidance — it is informational and does not restrict
                the date you may choose.
            </p>

            @error('scheduling')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Allotted to You</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>SN</th><th>Department</th><th>DPC P.No.</th><th>Proposal Date</th>
                                    <th>Post From</th><th>Post To</th><th>Default DPC Date</th>
                                    <th style="width:24rem">Action</th>
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
                                        <td>{{ $proposal->postFromCadre->name }}</td>
                                        <td>{{ $proposal->postToCadre->name }}</td>
                                        <td>{{ $allotment->default_dpc_date?->format('d/m/y') ?? '—' }}</td>
                                        <td>
                                            <span class="badge {{ $allotment->status->badgeClass() }}">{{ $allotment->status->label() }}</span>

                                            @if (! $isAllottee($allotment))
                                                <div class="small text-muted mt-1">Allotted to {{ $allotment->allottee->name }}</div>
                                            @elseif ($allotment->status->value === 'pending')
                                                <form method="POST" action="{{ route('cgpsc.schedules.accept', $proposal) }}" class="mt-1">
                                                    @csrf<button class="btn btn-primary btn-sm">Accept DPC</button>
                                                </form>
                                            @elseif ($allotment->status->value === 'accepted')
                                                <form method="POST" action="{{ route('cgpsc.schedules.store', $proposal) }}" class="row g-1 mt-1">
                                                    @csrf
                                                    <div class="col-5">
                                                        <input type="date" name="dpc_date" class="form-control form-control-sm" required>
                                                    </div>
                                                    <div class="col-4">
                                                        <input type="time" name="dpc_time" class="form-control form-control-sm" required>
                                                    </div>
                                                    <div class="col-3"><button class="btn btn-success btn-sm w-100">Schedule</button></div>
                                                </form>
                                            @elseif ($schedule !== null)
                                                @php($previous = $previousSchedules[$schedule->id] ?? null)
                                                <div class="small mt-1">
                                                    <strong>{{ $schedule->dpc_date->format('d/m/Y') }}</strong>
                                                    at <strong>{{ \Illuminate\Support\Carbon::parse($schedule->dpc_time)->format('h:i A') }}</strong>
                                                    @if ($previous !== null)
                                                        <span class="text-muted">
                                                            (was <s>{{ $previous->dpc_date->format('d/m/Y') }}
                                                            {{ \Illuminate\Support\Carbon::parse($previous->dpc_time)->format('h:i A') }}</s>)
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($isAllottee($allotment))
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer">Re-schedule</summary>
                                                        <form method="POST" action="{{ route('cgpsc.schedules.reschedule', $proposal) }}" class="row g-1 mt-1">
                                                            @csrf
                                                            <div class="col-5">
                                                                <input type="date" name="dpc_date" class="form-control form-control-sm" required>
                                                            </div>
                                                            <div class="col-4">
                                                                <input type="time" name="dpc_time" class="form-control form-control-sm" required>
                                                            </div>
                                                            <div class="col-3"><button class="btn btn-outline-warning btn-sm w-100">Move</button></div>
                                                            <div class="col-12">
                                                                <input type="text" name="reschedule_reason" class="form-control form-control-sm"
                                                                       placeholder="Reason for re-scheduling (required)" required>
                                                            </div>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif

                                            <div class="mt-1">
                                                <a href="{{ route('cgpsc.schedules.history', $proposal) }}" class="small">History</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">
                                        No DPC has been allotted to you yet.
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
                    <h2 class="h6">Scheduling Report</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>SN</th><th>Department</th><th>DPC P.No.</th><th>Proposal Date</th>
                                    <th>Post From</th><th>Post To</th><th>Scheduling</th><th>DPC Date</th><th>DPC Time</th>
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
                                        <td>{{ $proposal->postFromCadre->name }}</td>
                                        <td>{{ $proposal->postToCadre->name }}</td>
                                        <td><span class="badge text-bg-success">DONE</span></td>
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
                                    <tr><td colspan="9" class="text-center text-muted py-4">No DPC has been scheduled yet.</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
