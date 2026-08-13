@extends('layouts.app')

@section('title', 'Allotment of DPC')

@php($isChairman = auth()->user()->hasRole(\App\Enums\Role::Chairman->value))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Allotment of DPC</h1>
            <p class="text-muted small mb-3">
                Proposals approved by their department and forwarded to the Commission.
                Allotting never overwrites an earlier allotment — re-allotting records a new one and keeps the old.
            </p>

            @error('allotment')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            {{-- S28 — allotment --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Proposals Awaiting Action</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>SN</th><th>Department</th><th>DPC P.No.</th><th>Proposal Date</th>
                                    <th>Post From</th><th>Post To</th><th style="width:26rem">DPC Allotted To</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($proposals as $index => $proposal)
                                    @php($current = $currentByProposal[$proposal->id] ?? null)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->postFromCadre->name }}</td>
                                        <td>{{ $proposal->postToCadre->name }}</td>
                                        <td>
                                            @if ($current === null)
                                                @if ($isChairman)
                                                    <form method="POST" action="{{ route('cgpsc.allotments.store', $proposal) }}" class="row g-1">
                                                        @csrf
                                                        <div class="col-5">
                                                            <select name="allotted_to" class="form-select form-select-sm" required>
                                                                <option value="">Allot to…</option>
                                                                @foreach ($allottees as $allottee)
                                                                    <option value="{{ $allottee->id }}">{{ $allottee->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="col-4">
                                                            <input type="date" name="default_dpc_date" class="form-control form-control-sm"
                                                                   title="Default DPC date (optional)">
                                                        </div>
                                                        <div class="col-3"><button class="btn btn-primary btn-sm w-100">Allot</button></div>
                                                    </form>
                                                @else
                                                    <span class="text-muted small">Not yet allotted</span>
                                                @endif
                                            @else
                                                @php($superseded = $supersededByCurrent[$current->id] ?? null)
                                                <strong>{{ $current->allottee->name }}</strong>
                                                @if ($superseded !== null)
                                                    <span class="text-muted small">(was <s>{{ $superseded->allottee->name }}</s>)</span>
                                                @endif
                                                <span class="badge {{ $current->status->badgeClass() }}">{{ $current->status->label() }}</span>
                                                <div class="small text-muted">
                                                    allotted {{ $current->allotted_at->format('d/m/Y') }}
                                                    by {{ $current->allottedBy?->name ?? '—' }}
                                                </div>

                                                @if ($isChairman)
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer">Re-allot</summary>
                                                        <form method="POST" action="{{ route('cgpsc.allotments.reallot', $proposal) }}" class="row g-1 mt-1">
                                                            @csrf
                                                            <div class="col-5">
                                                                <select name="allotted_to" class="form-select form-select-sm" required>
                                                                    <option value="">Re-allot to…</option>
                                                                    @foreach ($allottees as $allottee)
                                                                        @if ($allottee->id !== $current->allotted_to)
                                                                            <option value="{{ $allottee->id }}">{{ $allottee->name }}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="col-4">
                                                                <input type="date" name="default_dpc_date" class="form-control form-control-sm">
                                                            </div>
                                                            <div class="col-3"><button class="btn btn-outline-warning btn-sm w-100">Re-allot</button></div>
                                                            <div class="col-12">
                                                                <input type="text" name="reallotment_reason" class="form-control form-control-sm"
                                                                       placeholder="Reason for re-allotment (required)" required>
                                                            </div>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">
                                        No proposal has been forwarded to the Commission yet.
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- S28 — DPC Allotment Report --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">DPC Allotment Report</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>SN</th><th>Department</th><th>DPC P.No.</th><th>Proposal Date</th>
                                    <th>Post From</th><th>Post To</th><th>DPC Allotted To</th>
                                    <th>Status</th><th>Default DPC Date</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($reported = 0)
                                @foreach ($proposals as $proposal)
                                    @php($current = $currentByProposal[$proposal->id] ?? null)
                                    @continue($current === null)
                                    @php($reported++)
                                    <tr>
                                        <td>{{ $reported }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->postFromCadre->name }}</td>
                                        <td>{{ $proposal->postToCadre->name }}</td>
                                        @php($superseded = $supersededByCurrent[$current->id] ?? null)
                                        <td>
                                            {{ $current->allottee->name }}
                                            @if ($superseded !== null)
                                                <br><s class="text-muted small">{{ $superseded->allottee->name }}</s>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $current->status->badgeClass() }}">{{ $current->status->label() }}</span></td>
                                        <td>{{ $current->default_dpc_date?->format('d/m/y') ?? '—' }}</td>
                                        <td>
                                            <a href="{{ route('cgpsc.allotments.history', $proposal) }}" class="btn btn-outline-secondary btn-sm">History</a>
                                        </td>
                                    </tr>
                                @endforeach

                                @if ($reported === 0)
                                    <tr><td colspan="10" class="text-center text-muted py-4">No DPC has been allotted yet.</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
