@extends('layouts.app')
@section('title', __('profile.title'))
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="h4 mb-1">{{ __('profile.title') }}</h1>
            <p class="text-muted small">{{ __('profile.lead') }}</p>

            @include('partials.alerts')

            <form method="POST" action="{{ route('profile.confirm') }}" class="card border-0 shadow-sm" id="officer-profile">
                @csrf
                <div class="card-body">
                    <dl class="row small mb-3">
                        <dt class="col-sm-4">{{ __('ui.name') }}</dt><dd class="col-sm-8">{{ $user->name }}</dd>
                        <dt class="col-sm-4">{{ __('ui.email') }}</dt><dd class="col-sm-8">{{ $user->email }}</dd>
                        <dt class="col-sm-4">{{ __('ui.mobile') }}</dt><dd class="col-sm-8">{{ $user->mobile }}</dd>
                        <dt class="col-sm-4">{{ __('identity.field.date_of_birth') }}</dt><dd class="col-sm-8">{{ $user->date_of_birth?->format('d-m-Y') ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('identity.field.gender') }}</dt><dd class="col-sm-8">{{ $user->gender ? __('identity.gender.'.$user->gender) : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('department.users.designation') }}</dt><dd class="col-sm-8">{{ $user->designation?->name ?? '—' }}</dd>
                    </dl>

                    <div class="mb-3">
                        <label for="department_id" class="form-label small fw-semibold">{{ __('ui.department') }}</label>
                        <select id="department_id" name="department_id" class="form-select form-select-sm @error('department_id') is-invalid @enderror" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((int) old('department_id', $user->department_id) === $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('profile.department_help') }}</div>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-check">
                        <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="confirm">
                        <label class="form-check-label small" for="confirm">{{ __('profile.disclaimer') }}</label>
                        @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('profile.continue') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
