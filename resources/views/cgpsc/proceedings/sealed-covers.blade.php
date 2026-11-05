@extends('layouts.app')

@section('title', __('dpc.sealed_cover.title'))

@section('content')
    {{-- Sealed covers of an approved DPC, opened by its own Chairman (2026-11-05, BD-48). --}}
    <h1 class="h4 mb-1">{{ __('dpc.sealed_cover.title') }}</h1>
    <p class="text-muted small">{{ $proposal->dpc_code ?? $proposal->id }} · {{ __('dpc.sealed_cover.intro') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    @forelse ($sealed as $entry)
        @php($result = $entry['result'])
        @php($opening = $entry['opening'])
        <div class="card border-0 shadow-sm mb-3" id="sealed-cover-{{ $result->sn }}">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>{{ $result->sn }}. {{ $result->officer_name }}</strong>
                <span class="badge {{ $opening === null ? 'text-bg-warning' : ($opening->isCleared() ? 'text-bg-success' : 'text-bg-danger') }}">
                    {{ $opening === null ? __('dpc.sealed_cover.sealed') : __('dpc.sealed_cover.outcomes.'.$opening->outcome) }}
                </span>
            </div>
            <div class="card-body">
                @if ($opening !== null)
                    <dl class="row small mb-0">
                        <dt class="col-sm-4">{{ __('dpc.sealed_cover.opened_on') }}</dt><dd class="col-sm-8">{{ $opening->opened_on->format('d-m-Y') }}</dd>
                        <dt class="col-sm-4">{{ __('dpc.sealed_cover.authority') }}</dt><dd class="col-sm-8">{{ $opening->authority_name }} — {{ $opening->authority_order_number }} ({{ $opening->authority_order_date->format('d-m-Y') }})</dd>
                        @if ($opening->replacement_status)
                            <dt class="col-sm-4">{{ __('dpc.sealed_cover.seat') }}</dt>
                            <dd class="col-sm-8" data-replacement-status="{{ $opening->replacement_status }}">{{ __('dpc.sealed_cover.replacement.'.$opening->replacement_status, ['name' => $opening->replacement_name]) }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('dpc.sealed_cover.document') }}</dt>
                        <dd class="col-sm-8"><a href="{{ route('dpc-proposals.sealed-covers.document', [$proposal, $opening]) }}">{{ $opening->original_name }}</a></dd>
                    </dl>
                @else
                    <form method="POST" enctype="multipart/form-data" action="{{ route('cgpsc.proceedings.sealed-covers.open', $proceeding) }}" class="row g-2">
                        @csrf
                        <input type="hidden" name="sn" value="{{ $result->sn }}">
                        <div class="col-md-4">
                            <span class="form-label small d-block">{{ __('dpc.sealed_cover.outcome') }}</span>
                            @foreach ([\App\Domain\Proceeding\Models\DpcSealedCoverOpening::CLEARED, \App\Domain\Proceeding\Models\DpcSealedCoverOpening::REJECTED] as $outcome)
                                <label class="form-check form-check-inline small">
                                    <input class="form-check-input" type="radio" name="outcome" value="{{ $outcome }}" required> {{ __('dpc.sealed_cover.outcomes.'.$outcome) }}
                                </label>
                            @endforeach
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="opened_on_{{ $result->sn }}">{{ __('dpc.sealed_cover.opened_on') }}</label>
                            <input type="date" class="form-control form-control-sm" id="opened_on_{{ $result->sn }}" name="opened_on" max="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small" for="authority_name_{{ $result->sn }}">{{ __('dpc.sealed_cover.authority_name') }}</label>
                            <input type="text" maxlength="255" class="form-control form-control-sm" id="authority_name_{{ $result->sn }}" name="authority_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="authority_order_number_{{ $result->sn }}">{{ __('dpc.sealed_cover.authority_order_number') }}</label>
                            <input type="text" maxlength="120" class="form-control form-control-sm" id="authority_order_number_{{ $result->sn }}" name="authority_order_number" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="authority_order_date_{{ $result->sn }}">{{ __('dpc.sealed_cover.authority_order_date') }}</label>
                            <input type="date" class="form-control form-control-sm" id="authority_order_date_{{ $result->sn }}" name="authority_order_date" max="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small" for="document_{{ $result->sn }}">{{ __('dpc.sealed_cover.document') }}</label>
                            <input type="file" accept="application/pdf,.pdf" class="form-control form-control-sm" id="document_{{ $result->sn }}" name="document" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small" for="remarks_{{ $result->sn }}">{{ __('dpc.sealed_cover.remarks') }}</label>
                            <textarea class="form-control form-control-sm" id="remarks_{{ $result->sn }}" name="remarks" rows="2" maxlength="2000"></textarea>
                            <div class="form-text">{{ __('dpc.sealed_cover.year_rule') }}</div>
                        </div>
                        <div class="col-12"><button class="btn btn-success btn-sm" data-confirm="{{ __('dpc.sealed_cover.confirm') }}">{{ __('dpc.sealed_cover.open') }}</button></div>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="alert alert-info">{{ __('dpc.sealed_cover.none') }}</div>
    @endforelse

    <a href="{{ route('cgpsc.proceedings.show', $proceeding) }}" class="btn btn-outline-secondary">{{ __('ui.back') }}</a>
@endsection
