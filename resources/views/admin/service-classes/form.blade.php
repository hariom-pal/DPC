@extends('layouts.admin')

@section('title', __('ui.class_master'))

@section('content')
    <h1 class="h4 mb-3">{{ $classMaster->exists ? __('ui.screen.edit_class') : __('ui.screen.new_class') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $classMaster->exists ? route('admin.service-classes.update', $classMaster) : route('admin.service-classes.store') }}">
                @csrf
                @if ($classMaster->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="code">{{ __('ui.code') }}</label>
                        <input type="text" maxlength="5" class="form-control text-uppercase @error('code') is-invalid @enderror" id="code" name="code"
                               value="{{ old('code', $classMaster->code) }}" required @readonly($classMaster->exists) placeholder="IV">
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.service_classes.form.hint_code') }}</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_en">{{ __('ui.name_english') }}</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $classMaster->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="name_hi">{{ __('ui.hindi') }}</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $classMaster->name_hi) }}">
                        @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.service_classes.form.hint_name_hi') }}</div>
                    </div>
                    <div class="col-md-1 mb-3">
                        <label class="form-label" for="level">{{ __('admin.service_classes.form.level') }}</label>
                        <input type="number" min="1" max="99" class="form-control @error('level') is-invalid @enderror"
                               id="level" name="level" value="{{ old('level', $classMaster->level) }}" required>
                        @error('level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 mb-3 form-text mt-0">{{ __('admin.service_classes.form.hint_level') }}</div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $classMaster->status?->value ?? 'active') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.service-classes.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
