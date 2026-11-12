{{-- Send a test message through the saved settings (2026-11-13). Expects: $channel. --}}
<div class="card border-0 shadow-sm" id="gateway-test">
    <div class="card-header bg-white"><strong>{{ __('admin.notification_gateways.test_heading') }}</strong></div>
    <div class="card-body">
        <p class="small text-muted">{{ __('admin.notification_gateways.test_help') }}</p>
        <form method="POST" action="{{ route('admin.notification-gateways.test', $channel) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-6">
                <label class="form-label small" for="test_to">{{ __('admin.notification_gateways.'.$channel.'.test_to') }}</label>
                <input type="{{ $channel === 'smtp' ? 'email' : 'text' }}" class="form-control form-control-sm @error('test_to') is-invalid @enderror" id="test_to" name="test_to"
                       value="{{ old('test_to') }}" required @if ($channel === 'sms') inputmode="numeric" maxlength="10" @endif>
                @error('test_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if ($channel === 'sms')
                {{-- A test SMS needs a DLT-approved text and its ID too (2026-11-13). --}}
                <div class="col-md-4">
                    <label class="form-label small" for="test_template_id">{{ __('admin.message_templates.dlt') }}</label>
                    <input type="text" class="form-control form-control-sm @error('test_template_id') is-invalid @enderror" id="test_template_id" name="test_template_id" inputmode="numeric" maxlength="30" value="{{ old('test_template_id') }}" required>
                    @error('test_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label small" for="test_message">{{ __('admin.notification_gateways.sms.test_text') }}</label>
                    <textarea class="form-control form-control-sm @error('test_message') is-invalid @enderror" id="test_message" name="test_message" rows="2" maxlength="1000" required>{{ old('test_message') }}</textarea>
                    @error('test_message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('admin.notification_gateways.sms.test_text_help') }}</div>
                </div>
            @endif
            <div class="col-md-2">
                <button class="btn btn-outline-primary btn-sm w-100">{{ __('admin.notification_gateways.send_test') }}</button>
            </div>
        </form>
    </div>
</div>
