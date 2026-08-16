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

@section('title', 'Circulation DPC — Commission')

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">Circulation {{ $circulation->circulation_code ?? '—' }}</h1>
        <p class="text-body-secondary">
            {{ $circulation->department?->name }} ·
            Origin DPC <strong>{{ $circulation->origin_dpc_code ?? '—' }}</strong>
            @if ($circulation->origin_dpc_date)
                dated {{ $circulation->origin_dpc_date->format('d-m-Y') }}
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
            The DPC is <strong>not re-run</strong>. No officer's marks are recalculated and no other officer's
            position changes. What this note decides is whether a promotion the Commission
            <strong>reserved</strong> now proceeds.
        </div>

        <h2 class="h6">Officers on this note</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Officer</th>
                        <th>Ground held on</th>
                        <th>What the department supplied</th>
                        <th>Received</th>
                        <th>Decision</th>
                        @can('consent', $circulation)
                            <th>Record</th>
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
                                    <span class="text-body-secondary">Not yet decided</span>
                                @endif
                                @if ($item->hold_released_at)
                                    <div class="small text-success">
                                        Released {{ $item->hold_released_at->format('d-m-Y H:i') }}
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
                                            Consent
                                        </button>
                                        <button name="decision" value="returned" class="btn btn-sm btn-outline-secondary">
                                            Return
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h2 class="h6">The DPC result — before and after</h2>
        @if ($rows->isEmpty())
            <div class="alert alert-warning">The origin DPC's approved result could not be located.</div>
        @else
            <p class="small text-body-secondary">
                Approved calculation run <code>{{ $run?->uuid ?? '—' }}</code>. Marks and positions are as
                approved at the original sitting and are identical in both columns.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Officer</th>
                            <th>Category</th>
                            <th>List</th>
                            <th>Remark — <em>before</em></th>
                            <th>Remark — <em>after</em></th>
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
                                        <span class="badge text-bg-warning">CIR</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $row['remark'] }}
                                    @if ($row['held_after'])
                                        <span class="badge text-bg-warning">CIR</span>
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
                        <button type="submit" class="btn btn-primary">APPROVE BY CGPSC</button>
                    </form>
                @endif
            @endcan

            @can('sign', $circulation)
                <form method="POST" action="{{ route('cgpsc.circulations.sign', $circulation) }}">
                    @csrf
                    {{-- The operative act: this is where reserved promotions
                         are released (AQ-17), and the screen says so. --}}
                    <button type="submit" class="btn btn-success">
                        Sign the circulation note — releases the consented promotions
                    </button>
                </form>
            @endcan
        </div>

        @can('returnToDepartment', $circulation)
            <form method="POST" action="{{ route('cgpsc.circulations.return', $circulation) }}" class="mt-3">
                @csrf
                <label for="remarks" class="form-label">Return to the department for further evidence</label>
                <textarea id="remarks" name="remarks" class="form-control mb-2" rows="2" required></textarea>
                <button type="submit" class="btn btn-outline-secondary">Return to department</button>
            </form>
        @endcan
    </div>
@endsection
