@extends('layouts.admin')

@section('title', __('ui.reservation_category'))

@section('content')
    <h1 class="h4 mb-3">{{ $category->exists ? __('ui.screen.edit_reservation_category') : __('ui.screen.new_reservation_category') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $category->exists ? route('admin.reservation-categories.update', $category) : route('admin.reservation-categories.store') }}">
                @csrf
                @if ($category->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">{{ __('ui.code') }}</label>
                        <input type="text" class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $category->code) }}" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_en">{{ __('ui.name_english') }}</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $category->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="name_hi">{{ __('ui.hindi') }}</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $category->name_hi) }}" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $category->status?->value ?? 'draft') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input type="hidden" name="is_reserved" value="0">
                    <input class="form-check-input" type="checkbox" id="is_reserved" name="is_reserved" value="1"
                        @checked(old('is_reserved', $category->is_reserved ?? true))>
                    <label class="form-check-label" for="is_reserved">{{ __('admin.reservation_categories.form.reserved_category') }}</label>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.reservation-categories.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
