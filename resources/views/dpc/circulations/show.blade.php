@extends('layouts.app')

@section('title', 'Circulation DPC')

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">
            Circulation {{ $circulation->circulation_code ?? '(draft)' }}
        </h1>
        <p class="text-body-secondary">
            Against DPC <strong>{{ $circulation->origin_dpc_code ?? '—' }}</strong>
            @if ($circulation->origin_dpc_date)
                dated {{ $circulation->origin_dpc_date->format('d-m-Y') }}
            @endif
        </p>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Stated on the page, not only in the design: the whole point of a
             circulation is that it changes nothing about the DPC's result. --}}
        <div class="alert alert-secondary">
            This circulation resolves a promotion that was <strong>reserved</strong> at the original sitting.
            It does not re-run the DPC, does not recalculate any officer's marks, and changes no other
            officer's position on the promotion list.
        </div>

        <div class="card mb-4">
            <div class="card-header">Status</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Current status</dt>
                    <dd class="col-sm-8">{{ $circulation->status->label() }}</dd>

                    <dt class="col-sm-4">Circulation proposal date</dt>
                    <dd class="col-sm-8">{{ $circulation->proposal_date->format('d-m-Y') }}</dd>

                    <dt class="col-sm-4">Raised by</dt>
                    <dd class="col-sm-8">
                        {{ $circulation->raised_by_name ?? '—' }}
                        @if ($circulation->raised_at)
                            <span class="text-body-secondary">on {{ $circulation->raised_at->format('d-m-Y H:i') }}</span>
                        @endif
                    </dd>

                    @if ($circulation->approved_at)
                        <dt class="col-sm-4">Approved by</dt>
                        <dd class="col-sm-8">
                            {{ $circulation->approved_by_name }}
                            <span class="text-body-secondary">on {{ $circulation->approved_at->format('d-m-Y H:i') }}</span>
                        </dd>
                    @endif

                    @if ($circulation->return_remarks)
                        <dt class="col-sm-4">Returned with remarks</dt>
                        <dd class="col-sm-8">{{ $circulation->return_remarks }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <h2 class="h6">Officers covered</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Officer</th>
                        <th>Ground the promotion was held on</th>
                        <th>Resolution received</th>
                        <th>What was received</th>
                        <th>Reference</th>
                        <th>Hold</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($circulation->items->sortBy('sn') as $item)
                        <tr>
                            <td>{{ $item->sn }}</td>
                            <td>{{ $item->officer_name }}</td>
                            <td>{{ $item->reason_code->label() }}</td>
                            <td>{{ $item->resolution_received_on->format('d-m-Y') }}</td>
                            <td>{{ $item->resolution_detail }}</td>
                            <td>{{ $item->resolution_reference ?? '—' }}</td>
                            <td>
                                @if ($item->hold_released_at)
                                    <span class="badge text-bg-success">Released</span>
                                @else
                                    {{-- Phase 2 releases the hold, at signature and
                                         not before (AQ-17). Until then the promotion
                                         is still reserved, and the screen says so. --}}
                                    <span class="badge text-bg-warning">Still held</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex gap-2 mt-3">
            @can('submit', $circulation)
                <form method="POST" action="{{ route('dpc-circulations.submit', $circulation) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Submit to Administrative Head</button>
                </form>
            @endcan

            @can('approve', $circulation)
                <form method="POST" action="{{ route('dpc-circulations.approve', $circulation) }}">
                    @csrf
                    <button type="submit" class="btn btn-success">Approve and sign</button>
                </form>
            @endcan

            @can('cancel', $circulation)
                <form method="POST" action="{{ route('dpc-circulations.cancel', $circulation) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">Cancel</button>
                </form>
            @endcan
        </div>

        @can('return', $circulation)
            <form method="POST" action="{{ route('dpc-circulations.return', $circulation) }}" class="mt-3">
                @csrf
                <label for="remarks" class="form-label">Return to the Authorized Officer with remarks</label>
                <textarea id="remarks" name="remarks" class="form-control mb-2" rows="2" required></textarea>
                <button type="submit" class="btn btn-outline-secondary">Return</button>
            </form>
        @endcan
    </div>
@endsection
