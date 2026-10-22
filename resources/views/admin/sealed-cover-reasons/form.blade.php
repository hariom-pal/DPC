@extends('layouts.admin')
@section('title', __('admin.sealed_cover_reasons.form_title'))
@section('content')
    <h1 class="h4 mb-3">
        {{ $reason->exists ? __('admin.sealed_cover_reasons.edit_heading') : __('admin.sealed_cover_reasons.new_heading') }}
    </h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $reason->exists ? route('admin.sealed-cover-reasons.update', $reason) : route('admin.sealed-cover-reasons.store') }}">
                @csrf
                @if ($reason->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">{{ __('ui.code') }}</label>
                        <input type="text" class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $reason->code) }}" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_en">{{ __('ui.name_english') }}</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $reason->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="name_hi">{{ __('ui.hindi') }}</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $reason->name_hi) }}" required>
                        @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $reason->status?->value ?? 'draft') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row align-items-start">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="sort_order">{{ __('admin.sealed_cover_reasons.order_label') }}</label>
                        <input type="number" min="0" max="9999"
                               class="form-control @error('sort_order') is-invalid @enderror"
                               id="sort_order" name="sort_order"
                               value="{{ old('sort_order', $reason->sort_order ?? 0) }}" required>
                        <div class="form-text">{{ __('admin.sealed_cover_reasons.order_help') }}</div>
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="effect">{{ __('admin.sealed_cover_reasons.effect_label') }}</label>
                        <select class="form-select @error('effect') is-invalid @enderror" id="effect" name="effect" required>
                            @foreach (\App\Domain\Master\Models\SealedCoverReason::effects() as $effect)
                                <option value="{{ $effect }}" @selected(old('effect', $reason->effect ?? 'sealed_cover') === $effect)>{{ __('admin.sealed_cover_reasons.effects.'.$effect) }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('admin.sealed_cover_reasons.effect_help') }}</div>
                        @error('effect')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5 mb-3">
                        <div class="form-check mt-4">
                            <input type="checkbox" class="form-check-input" id="requires_document"
                                   name="requires_document" value="1"
                                   @checked(old('requires_document', $reason->requires_document ?? true))>
                            <label class="form-check-label" for="requires_document">
                                {{ __('admin.sealed_cover_reasons.document_label') }}
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.sealed-cover-reasons.index') }}"
                   class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
