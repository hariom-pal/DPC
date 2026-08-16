@extends('layouts.app')

@section('title', 'Circulation DPCs — Commission')

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">Circulation DPCs — परिभ्रमण डी.पी.सी.</h1>
        <p class="text-body-secondary">
            Departments asking the Commission to consent to promotions it reserved, now that the missing
            record has been supplied.
        </p>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <h2 class="h6">Awaiting the Commission</h2>
        @if ($circulations->isEmpty())
            <div class="alert alert-info">Nothing is awaiting the Commission.</div>
        @else
            <div class="table-responsive mb-4">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Circulation</th>
                            <th>Department</th>
                            <th>Origin DPC</th>
                            <th>Officers</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($circulations as $circulation)
                            <tr>
                                <td>
                                    <a href="{{ route('cgpsc.circulations.show', $circulation) }}">
                                        {{ $circulation->circulation_code ?? '—' }}
                                    </a>
                                </td>
                                <td>{{ $circulation->department?->name ?? '—' }}</td>
                                <td>{{ $circulation->origin_dpc_code ?? '—' }}</td>
                                <td>{{ $circulation->items->count() }}</td>
                                <td>{{ $circulation->status->label() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($signed->isNotEmpty())
            <h2 class="h6">Recently signed</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Circulation</th>
                            <th>Department</th>
                            <th>Origin DPC</th>
                            <th>Promotions released</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($signed as $circulation)
                            <tr>
                                <td>
                                    <a href="{{ route('cgpsc.circulations.show', $circulation) }}">
                                        {{ $circulation->circulation_code }}
                                    </a>
                                </td>
                                <td>{{ $circulation->department?->name ?? '—' }}</td>
                                <td>{{ $circulation->origin_dpc_code ?? '—' }}</td>
                                <td>{{ $circulation->items->whereNotNull('hold_released_at')->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
