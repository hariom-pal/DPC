@extends('layouts.admin')
@section('title', __('admin.message_templates.title'))
@section('content')
    <h1 class="h4 mb-1">{{ $definition['label'] }} — {{ __('admin.message_templates.channels.'.$channel) }}</h1>
    <p class="text-muted small">
        <code>{{ $key }}</code> ·
        {{ $saved?->isActive() ? __('admin.message_templates.custom') : __('admin.message_templates.built_in') }} ·
        {{ __('admin.message_templates.statuses.'.($saved?->status ?? 'active')) }}
        @if ($channel === 'sms' && $source !== null)
            · {{ __('admin.message_templates.dlt_in_use') }}:
            @if ($source['dlt_template_id'] === null)
                <span class="badge text-bg-warning" id="dlt-in-use-missing">{{ __('admin.message_templates.dlt_missing') }}</span>
            @else
                <span id="dlt-in-use">{{ $source['dlt_template_id'] }}</span>
            @endif
        @endif
    </p>

    {{-- When the message goes, and to whom (2026-11-12). --}}
    <div class="alert alert-info small" id="template-when"><strong>{{ __('admin.message_templates.when') }}:</strong> {{ $definition['when'] }}</div>

    @include('partials.alerts', ['inlineErrors' => true])

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.message-templates.update', [$key, $channel]) }}" id="message-template-form">
                        @csrf @method('PUT')
                        @if ($channel === 'email')
                            <div class="mb-3">
                                <label class="form-label" for="subject">{{ __('admin.message_templates.subject') }}</label>
                                <input type="text" maxlength="255" class="form-control @error('subject') is-invalid @enderror" id="subject" name="subject"
                                       value="{{ old('subject', $saved?->subject ?? $default['subject']) }}" required>
                                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label" for="body">{{ __('admin.message_templates.body') }}</label>
                            <textarea class="form-control @error('body') is-invalid @enderror" id="body" name="body" rows="{{ $channel === 'email' ? 8 : 4 }}"
                                      maxlength="{{ $channel === 'email' ? 10000 : 1000 }}" required>{{ old('body', $saved?->body ?? $default['body']) }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if ($channel === 'sms')
                                <div class="form-text">{{ __('admin.message_templates.sms_help') }}</div>
                            @endif
                        </div>
                        @if ($channel === 'sms')
                            <div class="mb-3">
                                <label class="form-label" for="dlt_template_id">{{ __('admin.message_templates.dlt') }}</label>
                                <input type="text" inputmode="numeric" maxlength="30" class="form-control @error('dlt_template_id') is-invalid @enderror" id="dlt_template_id" name="dlt_template_id"
                                       value="{{ old('dlt_template_id', $saved?->dlt_template_id) }}">
                                @error('dlt_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">{{ __('admin.message_templates.dlt_help') }}</div>
                                @if ($saved !== null)
                                    <label class="form-label small mt-2 mb-1">{{ __('admin.message_templates.dlt_text') }}</label>
                                    <pre class="bg-body-secondary p-2 small mb-0" style="white-space: pre-wrap" id="custom-dlt-text">{{ \App\Domain\Notification\Templates\MessageTemplateCatalog::dltText($saved->body) }}</pre>
                                @endif
                            </div>
                        @endif
                        {{-- Draft, Active or Inactive (2026-11-13). --}}
                        <div class="mb-3">
                            <label class="form-label" for="status">{{ __('ui.status') }}</label>
                            <select class="form-select @error('status') is-invalid @enderror" id="status" name="status" required>
                                @foreach (\App\Domain\Notification\Models\MessageTemplate::STATUSES as $option)
                                    @continue($definition['mandatory'] && $option === 'inactive')
                                    <option value="{{ $option }}" @selected(old('status', $saved?->status ?? 'active') === $option)>{{ __('admin.message_templates.statuses.'.$option) }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('admin.message_templates.status_help') }}</div>
                            @if ($definition['mandatory'])<div class="form-text">{{ __('admin.message_templates.mandatory') }}</div>@endif
                        </div>
                        <button class="btn btn-success">{{ __('ui.save') }}</button>
                        <a href="{{ route('admin.message-templates.index', $channel) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                    </form>
                    @if ($saved)
                        <form method="POST" action="{{ route('admin.message-templates.destroy', [$key, $channel]) }}" class="mt-2" data-confirm="{{ __('admin.message_templates.restore_confirm') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">{{ __('admin.message_templates.restore') }}</button>
                        </form>
                    @endif
                </div>
            </div>
            {{-- The System default SMS text and its own DLT ID (2026-11-13): the text is
                 the application's and is not copied; only its ID is saved. --}}
            @if ($channel === 'sms')
                <div class="card border-0 shadow-sm mt-3" id="default-dlt">
                    <div class="card-header bg-white"><strong>{{ __('admin.message_templates.default_heading') }}</strong></div>
                    <div class="card-body">
                        <p class="small text-muted">{{ __('admin.message_templates.default_help') }}</p>
                        <label class="form-label small mb-1">{{ __('admin.message_templates.default_text') }}</label>
                        <pre class="bg-body-secondary p-2 small mb-2" style="white-space: pre-wrap" id="default-text">{{ $default['body'] }}</pre>
                        <label class="form-label small mb-1">{{ __('admin.message_templates.dlt_text') }}</label>
                        <pre class="bg-body-secondary p-2 small mb-3" style="white-space: pre-wrap" id="default-dlt-text">{{ \App\Domain\Notification\Templates\MessageTemplateCatalog::dltText($default['body']) }}</pre>
                        <form method="POST" action="{{ route('admin.message-templates.default-dlt', $key) }}" class="row g-2 align-items-end">
                            @csrf @method('PUT')
                            <div class="col-md-8">
                                <label class="form-label small" for="default_dlt_template_id">{{ __('admin.message_templates.dlt') }}</label>
                                <input type="text" inputmode="numeric" maxlength="30" class="form-control form-control-sm @error('default_dlt_template_id') is-invalid @enderror"
                                       id="default_dlt_template_id" name="default_dlt_template_id" value="{{ old('default_dlt_template_id', $defaultDltId) }}">
                                @error('default_dlt_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-success btn-sm w-100">{{ __('admin.message_templates.save_default_dlt') }}</button>
                            </div>
                        </form>
                        @if ($defaultDltId === null)
                            <div class="alert alert-warning small mt-2 mb-0">{{ __('admin.message_templates.default_dlt_missing') }}</div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>{{ __('admin.message_templates.placeholders') }}</strong></div>
                <div class="card-body small">
                    <ul class="mb-0" id="template-placeholders">
                        @foreach ($definition['placeholders'] as $name)
                            <li><code>{{ '{'.$name.'}' }}</code> @if (in_array($name, $definition['required'], true))<span class="badge text-bg-warning">{{ __('admin.message_templates.required') }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="card border-0 shadow-sm" id="template-preview">
                <div class="card-header bg-white"><strong>{{ __('admin.message_templates.preview') }}</strong></div>
                <div class="card-body small">
                    @if ($preview === null)
                        <span class="text-muted">{{ __('admin.message_templates.off') }}</span>
                    @else
                        @if ($channel === 'email')<div class="fw-semibold mb-1">{{ $preview['subject'] }}</div>@endif
                        <div style="white-space: pre-wrap">{{ $preview['body'] }}</div>
                        @if ($channel === 'sms')<div class="text-muted mt-1">{{ __('admin.message_templates.length', ['count' => mb_strlen($preview['body'])]) }}</div>@endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
