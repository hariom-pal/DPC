@extends('layouts.app')

@section('title', __('cgpsc.circulations.index.circulation_dpcs_commission'))

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">{{ __('ui.circulation_dpcs') }}</h1>
        <p class="text-body-secondary">
            {{ __('cgpsc.circulations.index.departments_asking_the_commission_to_consent_to') }}
        </p>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <h2 class="h6">{{ __('cgpsc.circulations.index.awaiting_the_commission') }}</h2>
        @if ($circulations->isEmpty())
            <div class="alert alert-info">{{ __('cgpsc.circulations.index.nothing_awaiting') }}</div>
        @else
            <div class="table-responsive mb-4">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.circulation') }}</th>
                            <th>{{ __('ui.department') }}</th>
                            <th>{{ __('ui.origin_dpc') }}</th>
                            <th>{{ __('ui.officers') }}</th>
                            <th>{{ __('ui.status') }}</th>
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
            <h2 class="h6">{{ __('cgpsc.circulations.index.recently_signed') }}</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.circulation') }}</th>
                            <th>{{ __('ui.department') }}</th>
                            <th>{{ __('ui.origin_dpc') }}</th>
                            <th>{{ __('cgpsc.circulations.index.promotions_released') }}</th>
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
