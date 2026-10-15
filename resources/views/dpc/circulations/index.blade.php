@extends('layouts.app')

@section('title', __('dpc.circulations.index.circulation_dpcs'))

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h4 mb-1">{{ __('ui.circulation_dpcs') }}</h1>
                <p class="text-body-secondary mb-0">
                    {{ __('dpc.circulations.index.promotions_the_commission_reserved_and_the_notes') }}
                </p>
            </div>
            @can('create', App\Domain\Circulation\Models\DpcCirculation::class)
                <a href="{{ route('dpc-circulations.create') }}" class="btn btn-primary">{{ __('dpc.circulations.index.raise_a_circulation') }}</a>
            @endcan
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($circulations->isEmpty())
            <div class="alert alert-info mb-0">{{ __('dpc.circulations.index.none_raised') }}</div>
        @else
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.circulation') }}</th>
                            <th>{{ __('ui.origin_dpc') }}</th>
                            <th>{{ __('dpc.circulations.index.proposal_date') }}</th>
                            <th>{{ __('ui.officers') }}</th>
                            <th>{{ __('ui.status') }}</th>
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
