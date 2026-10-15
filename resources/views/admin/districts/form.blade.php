@extends('layouts.admin')

@section('title', $district->exists ? 'Edit District' : 'New District')

@section('content')
    <h1 class="h4 mb-3">{{ $district->exists ? 'Edit District' : 'New District' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $district->exists ? route('admin.districts.update', $district) : route('admin.districts.store') }}">
                @csrf
                @if ($district->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name_en">{{ __('ui.name_english') }}</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $district->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name_hi">{{ __('ui.hindi') }}</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $district->name_hi) }}" required>
                        @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="state_lgd_code">{{ __('admin.districts.form.state_lgd_code') }}</label>
                        <input type="text" class="form-control" id="state_lgd_code" name="state_lgd_code"
                               value="{{ old('state_lgd_code', $district->state_lgd_code ?? '22') }}" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="lgd_code">{{ __('admin.districts.form.district_lgd_code') }}</label>
                        <input type="text" class="form-control @error('lgd_code') is-invalid @enderror"
                               id="lgd_code" name="lgd_code" value="{{ old('lgd_code', $district->lgd_code) }}">
                        @error('lgd_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $district->status?->value ?? 'draft') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.districts.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
