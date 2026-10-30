@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                <p class="text-body-secondary small">{{ __('invitations.register.step4') }}</p>

                @include('partials.alerts')

                <div class="gov-card">
                    <div class="card-body p-4">
                        {{-- From DigiLocker: read-only, and the account's identity. --}}
                        <dl class="row small" id="digilocker-identity">
                            <dt class="col-sm-5">{{ __('invitations.register.full_name') }}</dt><dd class="col-sm-7">{{ $identity['name'] }}</dd>
                            <dt class="col-sm-5">{{ __('identity.field.date_of_birth') }}</dt><dd class="col-sm-7">{{ \Illuminate\Support\Carbon::parse($identity['date_of_birth'])->format('d-m-Y') }}</dd>
                            <dt class="col-sm-5">{{ __('identity.field.gender') }}</dt><dd class="col-sm-7">{{ __('identity.gender.'.$identity['gender']) }}</dd>
                        </dl>
                        <p class="small text-body-secondary">{{ __('invitations.register.from_digilocker') }}</p>
                        <form method="POST" action="{{ route('register.invitation.complete', ['token' => $token]) }}">
                            @csrf
                            {{-- The Department Admin's post (MoM of 2026-10-29). --}}
                            <div class="mb-3">
                                <label for="designation_id" class="form-label">{{ __('invitations.register.post') }} <span class="text-danger">*</span></label>
                                <select class="form-select @error('designation_id') is-invalid @enderror" id="designation_id" name="designation_id" required
                                        data-searchable data-searchable-placeholder="{{ __('ui.search') }}">
                                    <option value="">{{ __('ui.select') }}</option>
                                    @foreach ($designations as $designation)
                                        <option value="{{ $designation->id }}" @selected((int) old('designation_id') === $designation->id)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                                @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">{{ __('invitations.register.password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required autocomplete="new-password">
                                <div class="form-text">{{ __('invitations.register.password_rules') }}</div>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">{{ __('invitations.register.password_confirm') }}</label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-success w-100">{{ __('invitations.register.complete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
