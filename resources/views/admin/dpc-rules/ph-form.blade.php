@extends('layouts.admin')

@section('title', __('dpcrules.ph.record_new'))

@section('content')
    <h1 class="h4 mb-1">{{ __('dpcrules.ph.record_new') }}</h1>
    <p class="text-muted small mb-3">{{ __('dpcrules.ph.form_intro') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.dpc-rules.ph-rules.store') }}">
        @csrf
        @include('admin.dpc-rules.year-field')

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>2. {{ __('dpcrules.ph.terms_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="percentage">{{ __('dpcrules.ph.percentage') }}</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control @error('percentage') is-invalid @enderror" id="percentage" name="percentage" value="{{ old('percentage', rtrim(rtrim($current['percentage'], '0'), '.')) }}" required>
                        @error('percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="rounding">{{ __('dpcrules.ph.rounding') }}</label>
                        <select class="form-select @error('rounding') is-invalid @enderror" id="rounding" name="rounding" required>
                            @foreach ($roundings as $rounding)
                                <option value="{{ $rounding }}" @selected(old('rounding', $current['rounding']) === $rounding)>{{ __('dpcrules.ph.roundings.'.$rounding) }}</option>
                            @endforeach
                        </select>
                        @error('rounding')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12"><div class="form-text">{{ __('dpcrules.ph.terms_help') }}</div></div>
                    <div class="col-12"><div class="small text-muted">{{ __('dpcrules.ph.fixed_terms') }}</div></div>
                </div>
            </div>
        </div>

        @include('admin.dpc-rules.document-fields', ['step' => 3])

        <button class="btn btn-primary">{{ __('dpcrules.save_version') }}</button>
        <a href="{{ route('admin.dpc-rules.index', ['year' => $year]) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
    </form>
@endsection
