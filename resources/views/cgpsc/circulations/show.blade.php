@extends('layouts.app')

{{--
    S48 — PROCESS OF CIRCULATION DPC / ACTION.

    The slide shows the same result table twice: once with `ST SS CIR` against
    the held officer, once with `ST SS`. That pairing is the screen's meaning —
    this is what the DPC decided, and this is what it decides once the reserved
    action is released.

    **Both columns are the same stored result.** The only thing that differs is
    the `CIR` annotation, and it comes from hold state, never from a second
    calculation (AD-23).
--}}

@section('title', __('cgpsc.circulations.show.circulation_dpc_commission'))

@section('content')
    <div class="container py-4">
        {!! __('cgpsc.circulations.show.heading', ['code' => e($circulation->circulation_code ?? '—')]) !!}
        <p class="text-body-secondary">
            {{ $circulation->department?->name }} ·
{!! __('cgpsc.circulations.show.origin_dpc', ['code' => e($circulation->origin_dpc_code ?? '—')]) !!}
            @if ($circulation->origin_dpc_date)
{{ __('cgpsc.circulations.show.dated', ['date' => $circulation->origin_dpc_date->format('d-m-Y')]) }}
            @endif
            · {{ $circulation->status->label() }}
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

        <div class="alert alert-secondary">
{!! __('cgpsc.circulations.show.lead') !!}
        </div>

        <h2 class="h6">{{ __('cgpsc.circulations.show.officers_on_this_note') }}</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.sn') }}</th>
                        <th>{{ __('ui.officer') }}</th>
                        <th>{{ __('cgpsc.circulations.show.ground_held_on') }}</th>
                        <th>{{ __('cgpsc.circulations.show.what_the_department_supplied') }}</th>
                        <th>{{ __('cgpsc.circulations.show.received') }}</th>
                        <th>{{ __('cgpsc.circulations.show.decision') }}</th>
                        @can('consent', $circulation)
                            <th>{{ __('ui.record') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($circulation->items->sortBy('sn') as $item)
                        <tr>
                            <td>{{ $item->sn }}</td>
                            <td>{{ $item->officer_name }}</td>
                            <td>{{ $item->reason_code->label() }}</td>
                            <td>{{ $item->resolution_detail }}</td>
                            <td>{{ $item->resolution_received_on->format('d-m-Y') }}</td>
                            <td>
                                @if ($item->decision)
                                    <span class="badge text-bg-{{ $item->decision->value === 'consented' ? 'success' : 'secondary' }}">
                                        {{ $item->decision->label() }}
                                    </span>
                                @else
                                    <span class="text-body-secondary">{{ __('cgpsc.circulations.show.not_yet_decided') }}</span>
                                @endif
                                @if ($item->hold_released_at)
                                    <div class="small text-success">
{{ __('cgpsc.circulations.show.released_at', ['at' => $item->hold_released_at->format('d-m-Y H:i')]) }}
                                    </div>
                                @endif
                            </td>
                            @can('consent', $circulation)
                                <td>
                                    <form method="POST"
                                          action="{{ route('cgpsc.circulations.decide', [$circulation, $item]) }}"
                                          class="d-flex gap-1">
                                        @csrf
                                        <button name="decision" value="consented" class="btn btn-sm btn-outline-success">
                                            {{ __('cgpsc.circulations.show.consent') }}
                                        </button>
                                        <button name="decision" value="returned" class="btn btn-sm btn-outline-warning">
                                            {{ __('ui.return') }}
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h2 class="h6">{{ __('cgpsc.circulations.show.the_dpc_result_before_and_after') }}</h2>
        @if ($rows->isEmpty())
            <div class="alert alert-warning">{{ __('cgpsc.circulations.show.origin_result_missing') }}</div>
        @else
            <p class="small text-body-secondary">
{!! __('cgpsc.circulations.show.run_provenance', ['uuid' => e($run?->uuid ?? '—')]) !!}
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.sn') }}</th>
                            <th>{{ __('ui.officer') }}</th>
                            <th>{{ __('ui.category') }}</th>
                            <th>{{ __('cgpsc.circulations.show.list') }}</th>
{!! __('cgpsc.circulations.show.remark_columns') !!}
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr @class(['table-warning' => $row['on_this_note']])>
                                <td>{{ $row['sn'] }}</td>
                                <td>{{ $row['officer_name'] }}</td>
                                <td>{{ $row['category'] }}</td>
                                <td>{{ $row['list_type']?->value ?? '—' }}</td>
                                <td>
                                    {{ $row['remark'] }}
                                    @if ($row['held_before'])
                                        <span class="badge text-bg-warning">{{ __('ui.cir') }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $row['remark'] }}
                                    @if ($row['held_after'])
                                        <span class="badge text-bg-warning">{{ __('ui.cir') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="d-flex gap-2 mt-3">
            @can('consent', $circulation)
                @if ($circulation->status->value === 'approved')
                    <form method="POST" action="{{ route('cgpsc.circulations.consent', $circulation) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">{{ __('ui.approve_by_cgpsc') }}</button>
                    </form>
                @endif
            @endcan

            @can('sign', $circulation)
                <form method="POST" action="{{ route('cgpsc.circulations.sign', $circulation) }}">
                    @csrf
                    {{-- The operative act: this is where reserved promotions
                         are released (AQ-17), and the screen says so. --}}
                    <button type="submit" class="btn btn-success">
                        {{ __('cgpsc.circulations.show.sign_the_circulation_note_releases_the_consented') }}
                    </button>
                </form>
            @endcan
        </div>

        @can('returnToDepartment', $circulation)
            <form method="POST" action="{{ route('cgpsc.circulations.return', $circulation) }}" class="mt-3">
                @csrf
                <label for="remarks" class="form-label">{{ __('cgpsc.circulations.show.return_to_the_department_for_further_evidence') }}</label>
                <textarea id="remarks" name="remarks" class="form-control mb-2" rows="2" required></textarea>
                <button type="submit" class="btn btn-outline-warning">{{ __('cgpsc.circulations.show.return_to_department') }}</button>
            </form>
        @endcan
    </div>
@endsection
