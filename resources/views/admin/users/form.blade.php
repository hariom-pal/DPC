@extends('layouts.admin')

@section('title', $user->exists ? 'Edit Account' : 'Provision Account')

@section('content')
    <h1 class="h4 mb-3">{{ $user->exists ? 'Edit Account' : 'Provision Account' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @unless ($user->exists)
                <p class="text-muted small">A temporary password is generated and sent to the account's mobile and email. The holder verifies both channels and sets a new password on first login.</p>
            @endunless

            <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
                @csrf
                @if ($user->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="role">Role</label>
                        <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                            <option value="">— Select —</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', $currentRole) === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="email">Email (.gov.in)</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="mobile">Mobile</label>
                        <input type="text" maxlength="10" class="form-control @error('mobile') is-invalid @enderror" id="mobile" name="mobile" value="{{ old('mobile', $user->mobile) }}" required>
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="designation_id">Designation</label>
                        <select class="form-select" id="designation_id" name="designation_id">
                            <option value="">— None —</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}" @selected(old('designation_id', $user->designation_id) == $designation->id)>{{ $designation->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ $user->exists ? 'Save' : 'Provision' }}</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
