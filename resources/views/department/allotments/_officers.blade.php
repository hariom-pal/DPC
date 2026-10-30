{{-- The AO and AH pickers (2026-10-28, reworked 2026-10-29). As the e-mail is typed
     the department's own officers and released officers are suggested; a chosen
     officer's details fill the same form a new officer is added with — name, date of
     birth, gender and e-mail read-only, the mobile changeable by OTP. An e-mail
     nobody has opens that form empty, in the Department Admin's department.
     Expects $current (the current allotment, or null) and $department. --}}
@foreach (['ao' => [$current?->ao, __('allotments.column.ao')], 'ah' => [$current?->ah, __('allotments.column.ah')]] as $slot => [$officer, $label])
    <div class="col-md-6">
        <div class="border rounded p-3 h-100 officer-picker" data-slot="{{ $slot }}"
             data-role="{{ $slot === 'ao' ? \App\Enums\Role::AuthorizedOfficer->value : \App\Enums\Role::AdministrativeHead->value }}"
             data-search-url="{{ route('department.officers.search') }}"
             data-lookup-url="{{ route('department.officers.lookup') }}"
             data-otp-url="{{ route('department.officers.otp') }}"
             data-store-url="{{ route('department.officers.store') }}"
             data-mobile-otp-url="{{ route('department.officers.mobile.otp', ['officer' => '__ID__']) }}"
             data-mobile-url="{{ route('department.officers.mobile', ['officer' => '__ID__']) }}"
             data-designation-url="{{ route('department.officers.designation', ['officer' => '__ID__']) }}"
             data-msg-not-found="{{ __('allotments.picker.not_found') }}"
             data-msg-no-match="{{ __('allotments.picker.no_match') }}"
             data-msg-searching="{{ __('allotments.picker.searching') }}"
             data-msg-error="{{ __('allotments.picker.error') }}"
             data-msg-selected="{{ __('allotments.picker.selected') }}"
             data-msg-released="{{ __('allotments.picker.released_badge') }}"
             data-msg-password="{{ __('allotments.picker.password') }}"
             data-msg-add-first="{{ __('allotments.picker.add_first') }}">
            <h2 class="h6">{{ $label }}</h2>
            <label class="form-label small" for="{{ $slot }}-email">{{ __('allotments.picker.email') }}</label>
            <div class="position-relative">
                <div class="input-group input-group-sm">
                    <input type="email" class="form-control picker-email" id="{{ $slot }}-email" value="{{ $officer?->email }}" autocomplete="off"
                           role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $slot }}-suggestions" aria-describedby="{{ $slot }}-email-hint">
                    <button type="button" class="btn btn-outline-primary picker-find">{{ __('allotments.picker.find') }}</button>
                </div>
                <div class="list-group position-absolute w-100 shadow-sm picker-suggestions d-none" id="{{ $slot }}-suggestions" role="listbox"></div>
            </div>
            <div class="form-text" id="{{ $slot }}-email-hint">{{ __('allotments.picker.hint') }}</div>
            <input type="hidden" name="{{ $slot }}_user_id" class="picker-id" value="{{ old($slot.'_user_id', $officer?->id) }}">
            @error($slot.'_user_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

            <div class="small mt-2 picker-result" aria-live="polite">
                @if ($officer)
                    <span class="badge text-bg-success">{{ __('allotments.picker.selected') }}</span> {{ $officer->name }}
                @endif
            </div>

            {{-- One form for a new officer and a chosen one. The fields carry no name:
                 only the picker sends them, to its own endpoints. --}}
            <div class="picker-details d-none mt-2 border-top pt-2">
                <p class="small text-muted mb-2 picker-lead-new">{{ __('allotments.picker.new_lead') }}</p>
                <p class="small text-muted mb-2 picker-lead-existing d-none">{{ __('allotments.picker.readonly_note') }}</p>
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
                        <input type="text" class="form-control form-control-sm" value="{{ $department?->name }}" readonly></div>
                    {{-- The post, from the Post (Designation) master (MoM of 2026-10-29):
                         editable for a chosen officer too, like the mobile. --}}
                    <div class="col-12"><label class="form-label small">{{ __('allotments.picker.post') }} <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <select class="form-select form-select-sm picker-designation">
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach ($designations as $designation)
                                    <option value="{{ $designation->id }}">{{ $designation->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-outline-success picker-designation-save d-none">{{ __('allotments.picker.save_post') }}</button>
                        </div></div>

                    {{-- A new officer: both OTPs, then add. --}}
                    <div class="col-12 picker-new-actions">
                        <div class="row g-2">
                            <div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm picker-send-otp">{{ __('allotments.picker.send_otp') }}</button></div>
                            <div class="col-6"><label class="form-label small">{{ __('allotments.picker.email_otp') }}</label>
                                <input type="text" class="form-control form-control-sm picker-email-code" maxlength="{{ config('dpc.otp.length') }}" inputmode="numeric"></div>
                            <div class="col-6"><label class="form-label small">{{ __('allotments.picker.mobile_otp') }}</label>
                                <input type="text" class="form-control form-control-sm picker-mobile-code" maxlength="{{ config('dpc.otp.length') }}" inputmode="numeric"></div>
                            <div class="col-12"><button type="button" class="btn btn-success btn-sm picker-add">{{ __('allotments.picker.add_as', ['role' => $label]) }}</button></div>
                        </div>
                    </div>

                    {{-- A chosen officer whose mobile is being changed: an OTP to the new number. --}}
                    <div class="col-12 picker-mobile-actions d-none">
                        <div class="row g-2 align-items-end">
                            <div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm picker-mobile-send">{{ __('allotments.picker.change_mobile') }}</button></div>
                            <div class="col-6"><label class="form-label small">{{ __('allotments.picker.mobile_otp') }}</label>
                                <input type="text" class="form-control form-control-sm picker-new-mobile-code" maxlength="{{ config('dpc.otp.length') }}" inputmode="numeric"></div>
                            <div class="col-6"><button type="button" class="btn btn-success btn-sm picker-mobile-save">{{ __('allotments.picker.save_mobile') }}</button></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="small mt-2 picker-message" aria-live="polite"></div>
        </div>
    </div>
@endforeach
<script src="@versioned('js/forms/officer-picker.js')" defer></script>
