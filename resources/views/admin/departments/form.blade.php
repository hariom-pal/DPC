@extends('layouts.admin')

@section('title', $department->exists ? __('ui.screen.edit_department') : __('ui.screen.new_department'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ $department->exists ? __('ui.screen.edit_department') : __('ui.screen.new_department') }}</h1>
        @if ($department->exists)
            <a href="{{ route('admin.departments.vacancy-master.index', $department) }}" class="btn btn-outline-secondary btn-sm">{{ __('vacancymaster.open_vacancy_master') }}</a>
        @endif
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $department->exists ? route('admin.departments.update', $department) : route('admin.departments.store') }}">
                @csrf
                @if ($department->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">{{ __('ui.code') }}</label>
                        <input type="text" class="form-control text-uppercase @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $department->code) }}"
                               maxlength="20" required @disabled($codeLocked)>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            @if ($codeLocked)
{{ __('admin.departments.form.code_locked') }}
                            @else
{!! __('admin.departments.form.code_hint') !!}
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name">{{ __('admin.departments.form.department_name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $department->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $department->status?->value ?? 'unregistered') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                        {!! __('admin.departments.form.status_hint') !!}
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="district_id">{{ __('ui.district') }}</label>
                        <select class="form-select @error('district_id') is-invalid @enderror" id="district_id" name="district_id">
                            <option value="">{{ __('admin.departments.form.not_set') }}</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}"
                                    @selected((int) old('district_id', $department->district_id) === $district->id)>
                                    {{ $district->name_en }}
                                </option>
                            @endforeach
                        </select>
                        @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="place">{{ __('admin.departments.form.place') }}</label>
                        <input type="text" class="form-control @error('place') is-invalid @enderror"
                               id="place" name="place" value="{{ old('place', $department->place) }}">
                        @error('place')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="pin">{{ __('admin.departments.form.pin') }}</label>
                        <input type="text" class="form-control @error('pin') is-invalid @enderror"
                               id="pin" name="pin" value="{{ old('pin', $department->pin) }}" maxlength="6">
                        @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="email">{{ __('admin.departments.form.departmental_contact_email') }}</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email', $department->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.departments.form.hint_captured_at_registration') }}</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.departments.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
