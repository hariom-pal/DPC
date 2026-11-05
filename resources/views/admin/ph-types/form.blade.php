@extends('layouts.admin')
@section('title', __('admin.ph_types.title'))
@section('content')
    <h1 class="h4 mb-3">{{ $type->exists ? __('admin.ph_types.edit_heading') : __('admin.ph_types.new_heading') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $type->exists ? route('admin.ph-types.update', $type) : route('admin.ph-types.store') }}">
                @csrf
                @if ($type->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="code">{{ __('ui.code') }}</label>
                        <input type="text" maxlength="20" class="form-control text-uppercase @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $type->code) }}" required>
                        <div class="form-text">{{ __('admin.ph_types.code_help') }}</div>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_en">{{ __('ui.name_english') }}</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $type->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_hi">{{ __('ui.hindi') }}</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $type->name_hi) }}" required>
                        @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $type->status?->value ?? 'active') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="category">{{ __('admin.ph_types.category') }}</label>
                        <select class="form-select @error('category') is-invalid @enderror" id="category" name="category">
                            <option value="">{{ __('ui.none') }}</option>
                            @foreach (\App\Domain\Master\Models\PhType::CATEGORIES as $category)
                                <option value="{{ $category }}" @selected(old('category', $type->category) === $category)>{{ __('admin.ph_types.categories.'.$category) }}</option>
                            @endforeach
                        </select>
                        @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="sort_order">{{ __('admin.ph_types.order') }}</label>
                        <input type="number" min="0" max="9999" class="form-control @error('sort_order') is-invalid @enderror"
                               id="sort_order" name="sort_order" value="{{ old('sort_order', $type->sort_order ?? 0) }}" required>
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.ph-types.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
