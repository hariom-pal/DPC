@extends('layouts.admin')
@section('title', __('admin.notification_gateways.sms.title'))
@section('content')
    <h1 class="h4 mb-1">{{ __('admin.notification_gateways.sms.title') }}</h1>
    <p class="text-muted small">{{ __('admin.notification_gateways.sms.intro') }}</p>

    @if ($missingDlt > 0)
        <div class="alert alert-warning" id="dlt-missing-warning" role="alert">
            <strong>{{ __('admin.message_templates.dlt_missing_heading', ['count' => $missingDlt]) }}</strong>
            <div class="small">{{ __('admin.message_templates.dlt_missing_help') }}
                <a href="{{ route('admin.message-templates.index', 'sms') }}">{{ __('ui.layouts.admin.nav.sms_templates') }}</a></div>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.notification-gateways.update', 'sms') }}" id="sms-settings" autocomplete="off">
                @csrf @method('PUT')
                <div class="form-check form-switch mb-3">
                    <input type="hidden" name="is_enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" @checked(old('is_enabled', $gateway?->is_enabled))>
                    <label class="form-check-label" for="is_enabled">{{ __('admin.notification_gateways.sms.enabled') }}</label>
                    <div class="form-text">{{ __('admin.notification_gateways.sms.enabled_help') }}</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label" for="url">{{ __('admin.notification_gateways.sms.url') }}</label>
                        <input type="url" class="form-control @error('url') is-invalid @enderror" id="url" name="url" maxlength="500" value="{{ old('url', $gateway?->setting('url')) }}">
                        @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="method">{{ __('admin.notification_gateways.sms.method') }}</label>
                        <select class="form-select" id="method" name="method">
                            @foreach (['post' => 'POST', 'get' => 'GET'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('method', $gateway?->setting('method', 'post')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="username">{{ __('admin.notification_gateways.username') }}</label>
                        <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username" maxlength="120" autocomplete="off" value="{{ old('username', $gateway?->setting('username')) }}">
                        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="secret">{{ __('admin.notification_gateways.sms.password') }}</label>
                        <input type="password" class="form-control @error('secret') is-invalid @enderror" id="secret" name="secret" maxlength="500" autocomplete="new-password"
                               placeholder="{{ $gateway?->secret ? __('admin.notification_gateways.secret_kept') : '' }}">
                        @error('secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.notification_gateways.secret_help') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="sender_id">{{ __('admin.notification_gateways.sms.sender_id') }}</label>
                        <input type="text" class="form-control @error('sender_id') is-invalid @enderror" id="sender_id" name="sender_id" maxlength="6" value="{{ old('sender_id', $gateway?->setting('sender_id')) }}">
                        @error('sender_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.notification_gateways.sms.sender_id_help') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="entity_id">{{ __('admin.notification_gateways.sms.entity_id') }}</label>
                        <input type="text" class="form-control @error('entity_id') is-invalid @enderror" id="entity_id" name="entity_id" inputmode="numeric" maxlength="30" value="{{ old('entity_id', $gateway?->setting('entity_id')) }}">
                        @error('entity_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.notification_gateways.sms.entity_id_help') }}</div>
                    </div>
                </div>
                <div class="alert alert-info small mt-3 mb-2">{{ __('admin.notification_gateways.sms.template_note') }}</div>

                {{-- Gateways name their API parameters differently (2026-11-13). --}}
                <details class="mt-2" id="sms-parameters">
                    <summary class="small text-primary">{{ __('admin.notification_gateways.sms.parameters') }}</summary>
                    <p class="small text-muted mt-2">{{ __('admin.notification_gateways.sms.parameters_help') }}</p>
                    <div class="row g-2">
                        @foreach ($parameters as $field => $default)
                            <div class="col-md-3">
                                <label class="form-label small" for="parameter-{{ $field }}">{{ __('admin.notification_gateways.sms.fields.'.$field) }}</label>
                                <input type="text" class="form-control form-control-sm @error('parameters.'.$field) is-invalid @enderror" id="parameter-{{ $field }}" name="parameters[{{ $field }}]" maxlength="60"
                                       placeholder="{{ $default }}" value="{{ old('parameters.'.$field, ($gateway?->setting('parameters', []) ?? [])[$field] ?? '') }}">
                                @error('parameters.'.$field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </details>
                <button class="btn btn-success mt-3">{{ __('ui.save') }}</button>
            </form>
        </div>
    </div>

    @include('admin.notification-gateways._test', ['channel' => 'sms'])
@endsection
