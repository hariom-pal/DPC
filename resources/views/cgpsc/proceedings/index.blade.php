@extends('layouts.app')

@section('title', 'Online DPC — Proceedings')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Online DPC — Proceedings</h1>
            <p class="text-muted small mb-3">
                DPCs that have been scheduled and are ready to be conducted. The sitting is opened by the
                member the DPC is allotted to. A Chairman who wishes to conduct a DPC re-allots it to
                himself first, so that one member remains accountable for the proceeding.
            </p>

            @error('proceeding')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>SN</th><th>Department</th><th>DPC ID</th>
                                    <th>Post From</th><th>Post To</th>
                                    <th>Allotted To</th><th>DPC Date &amp; Time</th>
                                    <th>Status</th><th style="width:16rem">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($allotments as $index => $allotment)
                                @php
                                    $proposal = $allotment->proposal;
                                    $schedule = $schedules[$allotment->id] ?? null;
                                    $proceeding = $proposal ? ($proceedings[$proposal->id] ?? null) : null;
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $proposal?->department?->name }}</td>
                                    <td><code>{{ $proposal?->dpc_code ?? '—' }}</code></td>
                                    <td>{{ $proposal?->postFromCadre?->name }}</td>
                                    <td>{{ $proposal?->postToCadre?->name }}</td>
                                    <td>{{ $allotment->allottee?->name }}</td>
                                    <td>
                                        @if ($schedule)
                                            {{ $schedule->dpc_date->format('d/m/Y') }}
                                            {{ \Illuminate\Support\Carbon::parse($schedule->dpc_time)->format('h:i A') }}
                                        @else
                                            <span class="text-muted">Not scheduled</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($proceeding)
                                            <span class="badge {{ $proceeding->status->badgeClass() }}">
                                                {{ $proceeding->status->label() }}
                                            </span>
                                        @elseif ($schedule)
                                            <span class="badge text-bg-secondary">Awaiting Proceeding</span>
                                        @else
                                            <span class="badge text-bg-light text-dark">Not Scheduled</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- `@can`, not `@if ($proceeding)`.
                                             Reading a proceeding belongs to the
                                             Chairman and to the Member who
                                             conducted it — and after a
                                             re-allotment those are not the same
                                             person as the current allottee, who
                                             is who this row is listed for. The
                                             plain @if offered that Member a
                                             button that could only 403. --}}
                                        @if ($proceeding && auth()->user()->can('view', $proceeding))
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ route('cgpsc.proceedings.show', $proceeding) }}">Open Workspace</a>
                                            <a class="btn btn-sm btn-link"
                                               href="{{ route('cgpsc.proceedings.history', $proceeding) }}">History</a>
                                        @elseif ($proceeding)
                                            <span class="text-muted small">
                                                {{-- The stored name, not the relation: it is already
                                                     on the row, and it is what the record says. --}}
                                                Conducted by {{ $proceeding->conducted_by_name ?: 'another Member' }}
                                            </span>
                                        @elseif ($schedule && $isAllottee($allotment))
                                            <form method="POST"
                                                  action="{{ route('cgpsc.proceedings.open', $proposal) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-primary">Open Proceeding</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-muted text-center py-3">
                                    No DPCs are awaiting a proceeding.
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
