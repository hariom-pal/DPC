@extends('layouts.app')

@section('title', 'Circulation DPCs')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h4 mb-1">Circulation DPCs — परिभ्रमण डी.पी.सी.</h1>
                <p class="text-body-secondary mb-0">
                    Promotions the Commission reserved, and the notes asking it to consent now that the
                    missing record has been supplied.
                </p>
            </div>
            @can('create', App\Domain\Circulation\Models\DpcCirculation::class)
                <a href="{{ route('dpc-circulations.create') }}" class="btn btn-primary">Raise a circulation</a>
            @endcan
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($circulations->isEmpty())
            <div class="alert alert-info mb-0">No circulation has been raised by this department.</div>
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Circulation</th>
                            <th>Origin DPC</th>
                            <th>Proposal date</th>
                            <th>Officers</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($circulations as $circulation)
                            <tr>
                                <td>
                                    <a href="{{ route('dpc-circulations.show', $circulation) }}">
                                        {{ $circulation->circulation_code ?? 'Draft' }}
                                    </a>
                                </td>
                                <td>{{ $circulation->origin_dpc_code ?? '—' }}</td>
                                <td>{{ $circulation->proposal_date->format('d-m-Y') }}</td>
                                <td>{{ $circulation->items->count() }}</td>
                                <td>{{ $circulation->status->label() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
