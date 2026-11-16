@extends('layouts.admin')
@section('title', __('admin.notification_gateways.smtp.title'))
@section('content')
    <h1 class="h4 mb-1">{{ __('admin.notification_gateways.smtp.title') }}</h1>
    <p class="text-muted small">{{ __('admin.notification_gateways.smtp.intro') }}</p>

    {{-- Gmail (2026-11-15): Google refuses the account password over SMTP; it takes an App Password. --}}
    <div class="card border-0 shadow-sm mb-3" data-gmail-help>
        <div class="card-body">
            <h2 class="h6 mb-1">{{ __('admin.notification_gateways.smtp.gmail.heading') }}</h2>
            <p class="small text-muted mb-2">{{ __('admin.notification_gateways.smtp.gmail.intro') }}</p>
            <ol class="small mb-3">
                @foreach ([1, 2, 3, 4] as $step)
                    <li>{{ __('admin.notification_gateways.smtp.gmail.step_'.$step) }}</li>
                @endforeach
            </ol>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary btn-sm" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer" data-gmail-app-password>{{ __('admin.notification_gateways.smtp.gmail.generate') }} ↗</a>
                <a class="btn btn-outline-secondary btn-sm" href="https://myaccount.google.com/signinoptions/twosv" target="_blank" rel="noopener noreferrer">{{ __('admin.notification_gateways.smtp.gmail.two_step') }} ↗</a>
                <button type="button" class="btn btn-outline-primary btn-sm" data-gmail-preset>{{ __('admin.notification_gateways.smtp.gmail.fill') }}</button>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.notification-gateways.update', 'smtp') }}" id="smtp-settings" autocomplete="off">
                @csrf @method('PUT')
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="is_enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" @checked(old('is_enabled', $gateway?->is_enabled))>
                    <label class="form-check-label" for="is_enabled">{{ __('admin.notification_gateways.smtp.enabled') }}</label>
                    <div class="form-text">{{ __('admin.notification_gateways.smtp.enabled_help') }}</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="host">{{ __('admin.notification_gateways.smtp.host') }}</label>
                        <input type="text" class="form-control @error('host') is-invalid @enderror" id="host" name="host" maxlength="255" value="{{ old('host', $gateway?->setting('host')) }}">
                        @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="port">{{ __('admin.notification_gateways.smtp.port') }}</label>
                        <input type="number" class="form-control @error('port') is-invalid @enderror" id="port" name="port" min="1" max="65535" value="{{ old('port', $gateway?->setting('port', 587)) }}">
                        @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="encryption">{{ __('admin.notification_gateways.smtp.encryption') }}</label>
                        <select class="form-select" id="encryption" name="encryption">
                            @foreach (['tls', 'ssl', 'none'] as $option)
                                <option value="{{ $option }}" @selected(old('encryption', $gateway?->setting('encryption', 'tls')) === $option)>{{ __('admin.notification_gateways.smtp.encryptions.'.$option) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="username">{{ __('admin.notification_gateways.username') }}</label>
                        <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username" maxlength="255" autocomplete="off" value="{{ old('username', $gateway?->setting('username')) }}">
                        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="secret">{{ __('admin.notification_gateways.smtp.password') }}</label>
                        <input type="password" class="form-control @error('secret') is-invalid @enderror" id="secret" name="secret" maxlength="500" autocomplete="new-password"
                               placeholder="{{ $gateway?->secret ? __('admin.notification_gateways.secret_kept') : '' }}">
                        @error('secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.notification_gateways.secret_help') }}</div>
                        <div class="form-text" data-gmail-only hidden>{{ __('admin.notification_gateways.smtp.gmail.password_help') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="from_address">{{ __('admin.notification_gateways.smtp.from_address') }}</label>
                        <input type="email" class="form-control @error('from_address') is-invalid @enderror" id="from_address" name="from_address" maxlength="255" value="{{ old('from_address', $gateway?->setting('from_address')) }}">
                        @error('from_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="from_name">{{ __('admin.notification_gateways.smtp.from_name') }}</label>
                        <input type="text" class="form-control @error('from_name') is-invalid @enderror" id="from_name" name="from_name" maxlength="120" value="{{ old('from_name', $gateway?->setting('from_name', config('app.name'))) }}">
                        @error('from_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button class="btn btn-success mt-3">{{ __('ui.save') }}</button>
            </form>
        </div>
    </div>

    @include('admin.notification-gateways._test', ['channel' => 'smtp'])

    @push('scripts')
        <script src="@versioned('js/forms/smtp-gmail.js')" defer></script>
    @endpush
@endsection
