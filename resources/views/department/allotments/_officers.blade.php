{{-- The AO and AH pickers (2026-10-28): type the officer's e-mail. An existing
     officer is chosen at once; otherwise add them here with both OTPs. Expects
     $current (the current allotment, or null) and $departments. --}}
@foreach (['ao' => [$current?->ao, __('allotments.column.ao')], 'ah' => [$current?->ah, __('allotments.column.ah')]] as $slot => [$officer, $label])
    <div class="col-md-6">
        <div class="border rounded p-3 h-100 officer-picker" data-slot="{{ $slot }}"
             data-role="{{ $slot === 'ao' ? \App\Enums\Role::AuthorizedOfficer->value : \App\Enums\Role::AdministrativeHead->value }}"
             data-lookup-url="{{ route('department.officers.lookup') }}"
             data-otp-url="{{ route('department.officers.otp') }}"
             data-store-url="{{ route('department.officers.store') }}"
             data-msg-not-found="{{ __('allotments.picker.not_found') }}"
             data-msg-error="{{ __('allotments.picker.error') }}"
             data-msg-selected="{{ __('allotments.picker.selected') }}"
             data-msg-password="{{ __('allotments.picker.password') }}"
             data-msg-add-first="{{ __('allotments.picker.add_first') }}">
            <h2 class="h6">{{ $label }}</h2>
            <label class="form-label small" for="{{ $slot }}-email">{{ __('allotments.picker.email') }}</label>
            <div class="input-group input-group-sm">
                <input type="email" class="form-control picker-email" id="{{ $slot }}-email" value="{{ $officer?->email }}" autocomplete="off">
                <button type="button" class="btn btn-outline-primary picker-find">{{ __('allotments.picker.find') }}</button>
            </div>
            <input type="hidden" name="{{ $slot }}_user_id" class="picker-id" value="{{ old($slot.'_user_id', $officer?->id) }}">
            @error($slot.'_user_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

            <div class="small mt-2 picker-result" aria-live="polite">
                @if ($officer)
                    <span class="badge text-bg-success">{{ __('allotments.picker.selected') }}</span> {{ $officer->name }}
                @endif
            </div>

            {{-- A new officer, added here. The fields carry no name: only the picker
                 sends them, to its own endpoints. --}}
            <div class="picker-new d-none mt-2 border-top pt-2">
                <p class="small text-muted mb-2">{{ __('allotments.picker.new_lead') }}</p>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small">{{ __('ui.name') }}</label>
                        <input type="text" class="form-control form-control-sm picker-name" maxlength="255"></div>
                    <div class="col-6"><label class="form-label small">{{ __('ui.mobile') }}</label>
                        <input type="text" class="form-control form-control-sm picker-mobile" maxlength="10" inputmode="numeric"></div>
                    <div class="col-6"><label class="form-label small">{{ __('identity.field.gender') }}</label>
                        <select class="form-select form-select-sm picker-gender">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach (['M', 'F'] as $gender)
                                <option value="{{ $gender }}">{{ __('identity.gender.'.$gender) }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-6"><label class="form-label small">{{ __('identity.field.date_of_birth') }}</label>
                        <input type="date" class="form-control form-control-sm picker-dob"></div>
                    <div class="col-6"><label class="form-label small">{{ __('ui.department') }}</label>
                        <select class="form-select form-select-sm picker-department">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm picker-send-otp">{{ __('allotments.picker.send_otp') }}</button></div>
                    <div class="col-6"><label class="form-label small">{{ __('allotments.picker.email_otp') }}</label>
                        <input type="text" class="form-control form-control-sm picker-email-code" maxlength="{{ config('dpc.otp.length') }}" inputmode="numeric"></div>
                    <div class="col-6"><label class="form-label small">{{ __('allotments.picker.mobile_otp') }}</label>
                        <input type="text" class="form-control form-control-sm picker-mobile-code" maxlength="{{ config('dpc.otp.length') }}" inputmode="numeric"></div>
                    <div class="col-12"><button type="button" class="btn btn-success btn-sm picker-add">{{ __('allotments.picker.add_as', ['role' => $label]) }}</button></div>
                </div>
            </div>
            <div class="small mt-2 picker-message" aria-live="polite"></div>
        </div>
    </div>
@endforeach
<script src="{{ asset('js/forms/officer-picker.js') }}" defer></script>
