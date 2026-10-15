@extends('layouts.app')

@section('title', __('dpc.circulations.show.circulation_dpc'))

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">
{{ __('dpc.circulations.show.heading', ['code' => $circulation->circulation_code ?? __('dpc.circulations.show.draft')]) }}
        </h1>
        <p class="text-body-secondary">
{!! __('dpc.circulations.show.against_dpc', ['code' => e($circulation->origin_dpc_code ?? '—')]) !!}
            @if ($circulation->origin_dpc_date)
{{ __('dpc.circulations.show.dated', ['date' => $circulation->origin_dpc_date->format('d-m-Y')]) }}
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
{!! __('dpc.circulations.show.lead') !!}
        </div>

        <div class="card mb-4">
            <div class="card-header">{{ __('dpc.circulations.show.status') }}</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('dpc.circulations.show.current_status') }}</dt>
                    <dd class="col-sm-8">{{ $circulation->status->label() }}</dd>

                    <dt class="col-sm-4">{{ __('dpc.circulations.show.circulation_proposal_date') }}</dt>
                    <dd class="col-sm-8">{{ $circulation->proposal_date->format('d-m-Y') }}</dd>

                    <dt class="col-sm-4">{{ __('dpc.circulations.show.raised_by') }}</dt>
                    <dd class="col-sm-8">
                        {{ $circulation->raised_by_name ?? '—' }}
                        @if ($circulation->raised_at)
                            {!! __('dpc.circulations.show.raised_on', ['at' => e($circulation->raised_at->format('d-m-Y H:i'))]) !!}
                        @endif
                    </dd>

                    @if ($circulation->approved_at)
                        <dt class="col-sm-4">{{ __('dpc.circulations.show.approved_by') }}</dt>
                        <dd class="col-sm-8">
                            {{ $circulation->approved_by_name }}
                            {!! __('dpc.circulations.show.approved_on', ['at' => e($circulation->approved_at->format('d-m-Y H:i'))]) !!}
                        </dd>
                    @endif

                    @if ($circulation->return_remarks)
                        <dt class="col-sm-4">{{ __('dpc.circulations.show.returned_with_remarks') }}</dt>
                        <dd class="col-sm-8">{{ $circulation->return_remarks }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <h2 class="h6">{{ __('dpc.circulations.show.officers_covered') }}</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.sn') }}</th>
                        <th>{{ __('ui.officer') }}</th>
                        <th>{{ __('dpc.circulations.show.ground_the_promotion_was_held_on') }}</th>
                        <th>{{ __('ui.resolution_received') }}</th>
                        <th>{{ __('ui.what_was_received') }}</th>
                        <th>{{ __('ui.reference') }}</th>
                        <th>{{ __('dpc.circulations.show.hold') }}</th>
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
                                    <span class="badge text-bg-success">{{ __('dpc.circulations.show.released') }}</span>
                                @else
                                    {{-- Phase 2 releases the hold, at signature and
                                         not before (AQ-17). Until then the promotion
                                         is still reserved, and the screen says so. --}}
                                    <span class="badge text-bg-warning">{{ __('dpc.circulations.show.still_held') }}</span>
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
                    <button type="submit" class="btn btn-primary">{{ __('dpc.circulations.show.submit_to_administrative_head') }}</button>
                </form>
            @endcan

            @can('approve', $circulation)
                <form method="POST" action="{{ route('dpc-circulations.approve', $circulation) }}">
                    @csrf
                    <button type="submit" class="btn btn-success">{{ __('dpc.circulations.show.approve_and_sign') }}</button>
                </form>
            @endcan

            @can('cancel', $circulation)
                <form method="POST" action="{{ route('dpc-circulations.cancel', $circulation) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">{{ __('ui.cancel') }}</button>
                </form>
            @endcan
        </div>

        @can('return', $circulation)
            <form method="POST" action="{{ route('dpc-circulations.return', $circulation) }}" class="mt-3">
                @csrf
                <label for="remarks" class="form-label">{{ __('dpc.circulations.show.return_to_the_authorized_officer_with_remarks') }}</label>
                <textarea id="remarks" name="remarks" class="form-control mb-2" rows="2" required></textarea>
                <button type="submit" class="btn btn-outline-secondary">{{ __('ui.return') }}</button>
            </form>
        @endcan
    </div>
@endsection
