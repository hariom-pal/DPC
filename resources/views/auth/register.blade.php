@extends('layouts.portal')

@section('title', __('authui.register.department_registration_e_dpc_portal'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
    <div class="container py-lg-3">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="text-center mb-4">
                @include('partials.logo', [
                    'which' => 'cgpsc', 'class' => 'mb-3', 'style' => 'height:56px', 'alt' => '',
                ])
                <h1 class="h4 mb-1">{{ __('authui.register.registration_of_department') }}</h1>
                <p class="text-body-secondary mb-0">{{ __('authui.register.text') }}</p>
            </div>

            {{-- What happens next, said before the form rather than after it:
                 a registrant who does not know the Commission must approve
                 will assume the portal is broken when nothing happens. --}}
            <div class="alert alert-info d-flex gap-2 align-items-start mb-4">
                @include('partials.icon', ['name' => 'flow-check', 'size' => 20])
                <div class="small">
                    <strong class="d-block mb-1">{{ __('authui.register.what_happens_after_you_submit') }}</strong>
{{ __('authui.register.what_happens_next') }}
                </div>
            </div>

            <div class="gov-card">
                <div class="card-body p-4 p-lg-5">

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('register.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="department_id" class="form-label">{{ __('authui.register.department_name') }}</label>
                            <select class="form-select @error('department_id') is-invalid @enderror"
                                    id="department_id" name="department_id" required>
                                <option value="">{{ __('authui.register.select_department') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="district_id" class="form-label">{{ __('authui.register.district') }}</label>
                                <select class="form-select @error('district_id') is-invalid @enderror" id="district_id" name="district_id" required>
                                    <option value="">{{ __('ui.select') }}</option>
                                    @foreach ($districts as $district)
                                        <option value="{{ $district->id }}" @selected(old('district_id') == $district->id)>{{ $district->name_en }} / {{ $district->name_hi }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="place" class="form-label">{{ __('authui.register.place') }}</label>
                                <input type="text" class="form-control" id="place" name="place" value="{{ old('place') }}" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="pin" class="form-label">{{ __('authui.register.pin') }}</label>
                                <input type="text" class="form-control" id="pin" name="pin" value="{{ old('pin') }}" maxlength="6" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="department_email" class="form-label">{{ __('authui.register.email_only_gov_id_department_contact_email') }}</label>
                            <input type="email" class="form-control @error('department_email') is-invalid @enderror"
                                   id="department_email" name="department_email" value="{{ old('department_email') }}" required>
                            @error('department_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <hr>
                        <h2 class="h6">{{ __('authui.register.administrative_head_level_2') }}</h2>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="ah_name" class="form-label">{{ __('authui.register.name') }}</label>
                                <input type="text" class="form-control" id="ah_name" name="ah_name" value="{{ old('ah_name') }}" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_designation_id" class="form-label">{{ __('authui.register.designation') }}</label>
                                <select class="form-select @error('ah_designation_id') is-invalid @enderror" id="ah_designation_id" name="ah_designation_id" required>
                                    <option value="">{{ __('ui.select') }}</option>
                                    @foreach ($designations as $designation)
                                        <option value="{{ $designation->id }}" @selected(old('ah_designation_id') == $designation->id)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_mobile" class="form-label">{{ __('authui.register.mobile') }}</label>
                                <input type="text" class="form-control" id="ah_mobile" name="ah_mobile" value="{{ old('ah_mobile') }}" maxlength="10" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_email" class="form-label">{{ __('authui.register.email_gov_in') }}</label>
                                <input type="email" class="form-control @error('ah_email') is-invalid @enderror" id="ah_email" name="ah_email" value="{{ old('ah_email') }}" required>
                            </div>
                        </div>

                        <hr>
                        <h2 class="h6">{{ __('authui.register.dpc_authorized_officer_level_1') }}</h2>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="ao_name" class="form-label">{{ __('authui.register.name') }}</label>
                                <input type="text" class="form-control" id="ao_name" name="ao_name" value="{{ old('ao_name') }}" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_designation_id" class="form-label">{{ __('authui.register.designation') }}</label>
                                <select class="form-select @error('ao_designation_id') is-invalid @enderror" id="ao_designation_id" name="ao_designation_id" required>
                                    <option value="">{{ __('ui.select') }}</option>
                                    @foreach ($designations as $designation)
                                        <option value="{{ $designation->id }}" @selected(old('ao_designation_id') == $designation->id)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_mobile" class="form-label">{{ __('authui.register.mobile_2') }}</label>
                                <input type="text" class="form-control" id="ao_mobile" name="ao_mobile" value="{{ old('ao_mobile') }}" maxlength="10" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_email" class="form-label">{{ __('authui.register.email_gov_in') }}</label>
                                <input type="email" class="form-control @error('ao_email') is-invalid @enderror" id="ao_email" name="ao_email" value="{{ old('ao_email') }}" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg px-4">{{ __('authui.register.register') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>
@endsection
