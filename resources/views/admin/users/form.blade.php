@extends('layouts.admin')

@section('title', $user->exists ? __('ui.screen.edit_account') : __('ui.screen.provision_account'))

@section('content')
    <h1 class="h4 mb-3">{{ $user->exists ? __('ui.screen.edit_account') : __('ui.screen.provision_account') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @unless ($user->exists)
                <p class="text-muted small">{{ __('admin.users.form.a_temporary_password_is_generated_and_sent_to_th') }}</p>
            @endunless

            <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
                @csrf
                @if ($user->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name">{{ __('ui.name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required @if ($user->exists) readonly @endif>
                        @if ($user->exists)
                            <div class="form-text"><a href="{{ route('admin.users.identity', $user) }}">{{ __('identity.name_via_identity') }}</a></div>
                        @endif
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @unless ($user->exists)
                        <div class="col-12">
                            @include('partials.identity-fields', ['dob' => null, 'gender' => null, 'required' => false])
                        </div>
                    @endunless
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="role">{{ __('ui.role') }}</label>
                        <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', $currentRole) === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- Department (change items 1 and 2). Shown for the departmental
                         roles only: a Commission role holds no department, and the
                         Form Request refuses one outright rather than ignoring it.
                         The control is disabled rather than hidden so the reason is
                         visible, and `public/js/forms/user-role.js` keeps it in step
                         with the Role select. --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="department_id">{{ __('ui.department') }}</label>
                        <select class="form-select @error('department_id') is-invalid @enderror"
                                id="department_id" name="department_id"
                                data-departmental-roles="{{ implode(',', $departmentalRoles) }}">
                            <option value="">{{ __('admin.users.form.none_commission_role') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id', $currentDepartmentId) == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text" id="department_hint">
                            {{ __('admin.users.form.hint_department_role') }}
                        </div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="email">{{ __('admin.users.form.email_gov_in') }}</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="mobile">{{ __('ui.mobile') }}</label>
                        <input type="text" maxlength="10" class="form-control @error('mobile') is-invalid @enderror" id="mobile" name="mobile" value="{{ old('mobile', $user->mobile) }}" required>
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="designation_id">{{ __('ui.designation') }}</label>
                        <select class="form-select" id="designation_id" name="designation_id">
                            <option value="">{{ __('ui.none') }}</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}" @selected(old('designation_id', $user->designation_id) == $designation->id)>{{ $designation->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ $user->exists ? __('ui.save') : __('ui.screen.provision') }}</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                @if ($user->exists)
                    <a href="{{ route('admin.users.assignments.index', $user) }}" class="btn btn-outline-primary float-end">
                        {{ __('admin.users.form.role_assignments_amp_transfers') }}
                    </a>
                @endif
            </form>
        </div>
    </div>

    @push('scripts')
        <script src="@versioned('js/forms/user-role.js')" defer></script>
    @endpush
@endsection
