@extends($layout)

@section('title', __('profile.account.title'))

@section('content')
    @php
        $verified = fn ($at) => $at
            ? '<span class="badge text-bg-success">'.e(__('profile.account.verified', ['at' => $at->format('d-m-Y')])).'</span>'
            : '<span class="badge text-bg-warning">'.e(__('profile.account.not_verified')).'</span>';
        $actingAs = $user->activeRole();
    @endphp

    <div class="row justify-content-center">
        <div class="col-xl-9">
            <h1 class="h4 mb-1">{{ __('profile.account.title') }}</h1>
            <p class="text-muted small">{{ __('profile.account.lead') }}</p>

            {{-- The administration layout shows the alerts itself. --}}
            @if ($layout !== 'layouts.admin')
                @include('partials.alerts')
            @endif

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100" id="account-details">
                        <div class="card-header fw-semibold">{{ __('profile.account.details') }}</div>
                        <div class="card-body">
                            <dl class="row small mb-0">
                                <dt class="col-sm-5">{{ __('ui.email') }}</dt>
                                <dd class="col-sm-7">{{ $user->email ?? '—' }} {!! $user->email ? $verified($user->email_verified_at) : '' !!}</dd>
                                <dt class="col-sm-5">{{ __('ui.mobile') }}</dt>
                                <dd class="col-sm-7">
                                    {{ $user->mobile ?? '—' }}
                                    @if ($user->mobile)
                                        @if ($user->hasMobileWaiver())
                                            <span class="badge text-bg-secondary">{{ __('profile.account.waived', ['reason' => $user->mobile_verification_waiver_reason]) }}</span>
                                        @else
                                            {!! $verified($user->mobile_verified_at) !!}
                                        @endif
                                    @endif
                                </dd>
                                <dt class="col-sm-5">{{ __('profile.account.employee_number') }}</dt>
                                <dd class="col-sm-7">{{ $user->employee_number ?: '—' }}</dd>
                                <dt class="col-sm-5">{{ __('department.users.designation') }}</dt>
                                <dd class="col-sm-7">{{ $user->designation?->name ?? '—' }}</dd>
                                <dt class="col-sm-5">{{ __('profile.account.roles') }}</dt>
                                <dd class="col-sm-7">{{ collect($user->grantedRoles())->map(fn ($role) => $role->label())->implode(', ') ?: '—' }}</dd>
                                @if ($actingAs)
                                    <dt class="col-sm-5">{{ __('profile.account.acting_as') }}</dt>
                                    <dd class="col-sm-7">{{ $actingAs->label() }}</dd>
                                @endif
                                <dt class="col-sm-5">{{ __('profile.account.password_changed') }}</dt>
                                <dd class="col-sm-7">{{ $user->password_changed_at?->format('d-m-Y H:i') ?? __('profile.account.never') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100" id="account-identity">
                        <div class="card-header fw-semibold">{{ __('profile.account.identity') }}</div>
                        <div class="card-body">
                            <dl class="row small">
                                <dt class="col-sm-5">{{ __('ui.name') }}</dt>
                                <dd class="col-sm-7">{{ $user->name }}</dd>
                                <dt class="col-sm-5">{{ __('identity.field.date_of_birth') }}</dt>
                                <dd class="col-sm-7">{{ $user->date_of_birth?->format('d-m-Y') ?? '—' }}</dd>
                                <dt class="col-sm-5">{{ __('identity.field.gender') }}</dt>
                                <dd class="col-sm-7">{{ $user->gender ? __('identity.gender.'.$user->gender) : '—' }}</dd>
                                <dt class="col-sm-5">{{ __('profile.account.identity_verified') }}</dt>
                                <dd class="col-sm-7">{!! $verified($user->identity_verified_at) !!}</dd>
                            </dl>
                            <p class="small text-muted mb-0">{{ __('profile.account.identity_note') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3" id="account-postings">
                <div class="card-header fw-semibold">{{ __('profile.account.postings') }}</div>
                <ul class="list-group list-group-flush small">
                    @forelse ($user->activeAssignments as $posting)
                        <li class="list-group-item">
                            <strong>{{ $posting->department?->name ?? '—' }}</strong> · {{ $posting->role->label() }}
                            <span class="text-muted">· {{ $posting->effective_from?->format('d-m-Y') }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">{{ __('profile.account.no_postings') }}</li>
                    @endforelse
                </ul>
            </div>

            {{-- The e-mail and mobile change, proved by OTP on both (2026-11-03). --}}
            <div class="card border-0 shadow-sm" id="account-contact">
                <div class="card-header fw-semibold">{{ __('profile.account.change_title') }}</div>
                <div class="card-body">
                    @if (is_array($pending))
                        <h2 class="h6">{{ __('profile.account.verify_title') }}</h2>
                        <p class="small text-muted">
                            {{ $mobileCodesUsable
                                ? __('profile.account.verify_lead', ['email' => $pending['email'], 'mobile' => $pending['mobile']])
                                : __('profile.account.verify_lead_no_sms', ['email' => $pending['email']]) }}
                        </p>
                        <form method="POST" action="{{ route('account.contact.verify') }}" class="row g-2 align-items-end" id="account-contact-verify">
                            @csrf
                            <div class="col-sm-4">
                                <label class="form-label small" for="email_code">{{ __('profile.account.email_code') }}</label>
                                <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" class="form-control @error('email_code') is-invalid @enderror" id="email_code" name="email_code" required>
                                @error('email_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            @if ($mobileCodesUsable)
                                <div class="col-sm-4">
                                    <label class="form-label small" for="mobile_code">{{ __('profile.account.mobile_code') }}</label>
                                    <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" class="form-control @error('mobile_code') is-invalid @enderror" id="mobile_code" name="mobile_code" required>
                                    @error('mobile_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endif
                            <div class="col-sm-auto">
                                <button class="btn btn-primary">{{ __('profile.account.confirm') }}</button>
                            </div>
                        </form>
                        <div class="d-flex gap-2 mt-2">
                            <form method="POST" action="{{ route('account.contact.resend') }}">@csrf<button class="btn btn-link btn-sm px-0">{{ __('profile.account.resend') }}</button></form>
                            <form method="POST" action="{{ route('account.contact.cancel') }}">@csrf<button class="btn btn-link btn-sm text-danger">{{ __('profile.account.cancel') }}</button></form>
                        </div>
                    @else
                        <p class="small text-muted">{{ $mobileCodesUsable ? __('profile.account.change_lead') : __('profile.account.change_lead_no_sms') }}</p>
                        <form method="POST" action="{{ route('account.contact.start') }}" class="row g-2 align-items-end" id="account-contact-start">
                            @csrf
                            <div class="col-md-6">
                                <label class="form-label small" for="email">{{ __('ui.email') }}</label>
                                <input type="email" maxlength="255" class="form-control @error('email', 'contact') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                                @error('email', 'contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="mobile">{{ __('ui.mobile') }}</label>
                                <input type="text" inputmode="numeric" maxlength="10" class="form-control @error('mobile', 'contact') is-invalid @enderror" id="mobile" name="mobile" value="{{ old('mobile', $user->mobile) }}" required>
                                @error('mobile', 'contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="contact_current_password">{{ __('profile.account.current_password') }}</label>
                                <input type="password" autocomplete="current-password" class="form-control @error('current_password', 'contact') is-invalid @enderror" id="contact_current_password" name="current_password" required>
                                @error('current_password', 'contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">{{ __('profile.account.current_password_hint') }}</div>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary w-100">{{ __('profile.account.send_codes') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Password, with the current one (2026-11-03). The same route as the
                 first-login change, which already demands it. --}}
            <div class="card border-0 shadow-sm mt-3" id="account-password">
                <div class="card-header fw-semibold">{{ __('profile.account.password_title') }}</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('password.change.update') }}" class="row g-2 align-items-end" id="account-password-form">
                        @csrf
                        <input type="hidden" name="return_to" value="account">
                        <div class="col-md-4">
                            <label class="form-label small" for="password_current">{{ __('profile.account.current_password') }}</label>
                            <input type="password" autocomplete="current-password" class="form-control @error('current_password') is-invalid @enderror" id="password_current" name="current_password" required>
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="new_password">{{ __('profile.account.new_password') }}</label>
                            <input type="password" autocomplete="new-password" class="form-control @error('password') is-invalid @enderror" id="new_password" name="password" required>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="new_password_confirmation">{{ __('profile.account.confirm_password') }}</label>
                            <input type="password" autocomplete="new-password" class="form-control" id="new_password_confirmation" name="password_confirmation" required>
                        </div>
                        <div class="col-12 form-text mt-0">{{ __('profile.account.password_rule') }}</div>
                        <div class="col-12">
                            <button class="btn btn-outline-primary">{{ __('profile.account.change_password') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
