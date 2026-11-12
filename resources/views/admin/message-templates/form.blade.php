@extends('layouts.admin')
@section('title', __('admin.message_templates.title'))
@section('content')
    <h1 class="h4 mb-1">{{ $definition['label'] }} — {{ __('admin.message_templates.channels.'.$channel) }}</h1>
    <p class="text-muted small">
        <code>{{ $key }}</code> ·
        {{ $saved ? __('admin.message_templates.custom') : __('admin.message_templates.built_in') }}
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
                            </div>
                        @endif
                        <div class="form-check mb-3">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" class="form-check-input @error('is_active') is-invalid @enderror" id="is_active" name="is_active" value="1"
                                   @checked(old('is_active', $saved?->is_active ?? true)) @disabled($definition['mandatory'])>
                            @if ($definition['mandatory'])<input type="hidden" name="is_active" value="1">@endif
                            <label class="form-check-label" for="is_active">{{ __('admin.message_templates.send_this') }}</label>
                            @error('is_active')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if ($definition['mandatory'])<div class="form-text">{{ __('admin.message_templates.mandatory') }}</div>@endif
                        </div>
                        <button class="btn btn-success">{{ __('ui.save') }}</button>
                        <a href="{{ route('admin.message-templates.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                    </form>
                    @if ($saved)
                        <form method="POST" action="{{ route('admin.message-templates.destroy', [$key, $channel]) }}" class="mt-2" data-confirm="{{ __('admin.message_templates.restore_confirm') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">{{ __('admin.message_templates.restore') }}</button>
                        </form>
                    @endif
                </div>
            </div>
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
