@extends('layouts.app')
@section('title', __('dpc.promotion_orders.title'))
@section('content')
    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_code ?? __('dpc.record_gaps.proposal') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('dpc.promotion_orders.title') }}</li>
        </ol>
    </nav>

    <h1 class="h4 mb-1">{{ __('dpc.promotion_orders.title') }}</h1>
    <p class="text-muted mb-3">{{ __('dpc.promotion_orders.lead') }}</p>

    @include('partials.alerts')
    @error('order')<div class="alert alert-danger">{{ $message }}</div>@enderror

    @if ($proceeding === null)
        <div class="alert alert-warning" id="promotion-order-not-ready">{{ __('dpc.promotion_orders.no_approved_result') }}</div>
    @else
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">{{ __('dpc.promotion_orders.promoted_heading', ['count' => $promoted->count()]) }}</h2>
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="promotion-order-officers">
                        <thead><tr><th>#</th><th>{{ __('ui.name') }}</th><th>{{ __('dpc.employee_details.employee_id') }}</th><th>{{ __('ui.reservation_category') }}</th></tr></thead>
                        <tbody>
                            @foreach ($promoted as $index => $result)
                                <tr><td>{{ $index + 1 }}</td><td>{{ $result->officer_name }}</td><td>{{ $result->employee_number ?? '—' }}</td><td>{{ $result->reservation_category_code }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">{{ __('dpc.promotion_orders.sealed_cover_note') }}</p>
            </div>
        </div>

        @if ($canIssue)
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ $orders->isEmpty() ? __('dpc.promotion_orders.issue_heading') : __('dpc.promotion_orders.reissue_heading') }}</h2>
                    <form method="POST" action="{{ route('dpc-proposals.promotion-orders.store', $proposal) }}" class="row g-2 align-items-end" id="promotion-order-form">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label small" for="order_number">{{ __('dpc.promotion_orders.order_number') }}</label>
                            <input class="form-control form-control-sm @error('order_number') is-invalid @enderror" id="order_number" name="order_number" maxlength="120" required value="{{ old('order_number') }}">
                            @error('order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="order_date">{{ __('dpc.promotion_orders.order_date') }}</label>
                            <input type="date" class="form-control form-control-sm @error('order_date') is-invalid @enderror" id="order_date" name="order_date" required value="{{ old('order_date') }}">
                            @error('order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" required>
                                {{ __('dpc.promotion_orders.confirm') }}
                            </label>
                        </div>
                        <div class="col-12"><button class="btn btn-success btn-sm">{{ __('dpc.promotion_orders.issue_button') }}</button></div>
                    </form>
                </div>
            </div>
        @endif
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6">{{ __('dpc.promotion_orders.history') }}</h2>
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="promotion-orders">
                    <thead><tr>
                        <th>{{ __('dpc.promotion_orders.order_number') }}</th><th>{{ __('dpc.promotion_orders.order_date') }}</th>
                        <th>{{ __('dpc.promotion_orders.version') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('dpc.promotion_orders.issued_by') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr data-order-status="{{ $order->status }}">
                                <td>{{ $order->order_number }}</td>
                                <td>{{ $order->order_date->format('d-m-Y') }}</td>
                                <td>{{ $order->version }}</td>
                                <td><span class="badge {{ $order->status === 'issued' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ __('dpc.promotion_orders.statuses.'.$order->status) }}</span></td>
                                <td>{{ $order->issuer?->name ?? '—' }} · {{ $order->issued_at->format('d-m-Y H:i') }}</td>
                                <td>
                                    @if ($order->content_hash)
                                        <a href="{{ route('dpc-proposals.promotion-orders.download', [$proposal, $order]) }}">{{ __('dpc.promotion_orders.open_pdf') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">{{ __('dpc.promotion_orders.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.back') }}</a>
@endsection
