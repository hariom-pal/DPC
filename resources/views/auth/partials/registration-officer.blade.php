{{-- One officer's seat on the registration form (business decision, 2026-10-22).
     The Aadhaar number and both OTPs carry no name attribute: they go to the OTP
     endpoints only and are never part of the Register submission. --}}
@php
    $s = $seat->value;
    $isVerified = $verifiedOfficer !== null;
    $email = $isVerified ? $verifiedOfficer['email'] : old($s.'_email');
    $name = $isVerified ? $verifiedOfficer['name'] : old($s.'_name');
    $mobile = $isVerified ? $verifiedOfficer['mobile'] : old($s.'_mobile');
@endphp
<section class="registration-officer" data-seat="{{ $s }}" data-verified="{{ $isVerified ? '1' : '0' }}">
    <h2 class="h6 d-flex align-items-center gap-2">
        {{ $heading }}
        <span class="badge text-bg-success {{ $isVerified ? '' : 'd-none' }}" data-role="badge">{{ __('authui.register.verified_badge') }}</span>
    </h2>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="{{ $s }}_email" class="form-label">{{ __('authui.register.email') }}</label>
            <input type="email" class="form-control @error($s.'_email') is-invalid @enderror" id="{{ $s }}_email" name="{{ $s }}_email"
                   value="{{ $email }}" data-lock autocomplete="off" @readonly($isVerified)>
            @error($s.'_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="{{ $s }}_name" class="form-label">{{ __('authui.register.name') }}</label>
            <input type="text" class="form-control @error($s.'_name') is-invalid @enderror" id="{{ $s }}_name" name="{{ $s }}_name"
                   value="{{ $name }}" data-lock @readonly($isVerified)>
            <div class="form-text" data-role="name-hint"></div>
            @error($s.'_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="{{ $s }}_designation_id" class="form-label">{{ __('authui.register.designation') }}</label>
            <select class="form-select @error($s.'_designation_id') is-invalid @enderror" id="{{ $s }}_designation_id" name="{{ $s }}_designation_id">
                <option value="">{{ __('ui.select') }}</option>
                @foreach ($designations as $designation)
                    <option value="{{ $designation->id }}" @selected(old($s.'_designation_id') == $designation->id)>{{ $designation->name }}</option>
                @endforeach
            </select>
            @error($s.'_designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="{{ $s }}_mobile" class="form-label">{{ __('authui.register.mobile') }}</label>
            <input type="text" class="form-control @error($s.'_mobile') is-invalid @enderror" id="{{ $s }}_mobile" name="{{ $s }}_mobile"
                   value="{{ $mobile }}" maxlength="10" inputmode="numeric" data-lock @readonly($isVerified)>
            @error($s.'_mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 mb-3">
            <label for="{{ $s }}_aadhaar" class="form-label">{{ __('authui.register.aadhaar') }}</label>
            <input type="password" class="form-control" id="{{ $s }}_aadhaar" maxlength="12" inputmode="numeric"
                   autocomplete="off" data-lock data-role="aadhaar" @readonly($isVerified)
                   value="" placeholder="{{ $isVerified ? 'XXXX XXXX '.$verifiedOfficer['aadhaar_last4'] : '' }}">
            <div class="form-text" data-role="aadhaar-masked">{{ $isVerified ? 'XXXX XXXX '.$verifiedOfficer['aadhaar_last4'] : '' }}</div>
            <div class="form-text">{{ __('authui.register.aadhaar_hint') }}</div>
        </div>
        <div class="col-md-6 mb-3 d-flex align-items-end gap-2">
            <button type="button" class="btn btn-outline-primary {{ $isVerified ? 'd-none' : '' }}" data-role="send">{{ __('authui.register.send_otp') }}</button>
            <button type="button" class="btn btn-outline-secondary {{ $isVerified ? '' : 'd-none' }}" data-role="change">{{ __('authui.register.change') }}</button>
        </div>
    </div>

    <div class="row d-none" data-role="otp">
        <div class="col-md-5 mb-3">
            <label for="{{ $s }}_aadhaar_code" class="form-label">{{ __('authui.register.aadhaar_otp') }}</label>
            <input type="text" class="form-control" id="{{ $s }}_aadhaar_code" maxlength="8" inputmode="numeric" autocomplete="one-time-code" data-role="aadhaar-code">
        </div>
        <div class="col-md-4 mb-3">
            <label for="{{ $s }}_email_code" class="form-label">{{ __('authui.register.email_otp') }}</label>
            <input type="text" class="form-control" id="{{ $s }}_email_code" maxlength="8" inputmode="numeric" autocomplete="off" data-role="email-code">
        </div>
        <div class="col-md-3 mb-3 d-flex align-items-end">
            <button type="button" class="btn btn-primary w-100" data-role="verify">{{ __('authui.register.verify_otp') }}</button>
        </div>
    </div>

    <div class="small" data-role="status" aria-live="polite"></div>
</section>
