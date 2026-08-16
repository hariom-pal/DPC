@extends('layouts.portal')

@section('title', 'Department Registration — e-DPC Portal')

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
    <div class="container py-lg-3">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <div class="text-center mb-4">
                <img src="{{ asset('images/logo/cgpsc-logo.svg') }}" alt="" style="height:56px" class="mb-3">
                <h1 class="h4 mb-1">Registration of Department</h1>
                <p class="text-body-secondary mb-0">विभाग का पंजीयन</p>
            </div>

            {{-- What happens next, said before the form rather than after it:
                 a registrant who does not know the Commission must approve
                 will assume the portal is broken when nothing happens. --}}
            <div class="alert alert-info d-flex gap-2 align-items-start mb-4">
                @include('partials.icon', ['name' => 'flow-check', 'size' => 20])
                <div class="small">
                    <strong class="d-block mb-1">What happens after you submit</strong>
                    Your details are verified by e-mail and mobile OTP. The Commission then approves the
                    registration and issues credentials to the Authorized Officer and the Administrative Head.
                </div>
            </div>

            <div class="gov-card">
                <div class="card-body p-4 p-lg-5">

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('register.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="department_id" class="form-label">विभाग का नाम / Department Name</label>
                            <select class="form-select @error('department_id') is-invalid @enderror"
                                    id="department_id" name="department_id" required>
                                <option value="">— Select Department —</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="district_id" class="form-label">जिला / District</label>
                                <select class="form-select @error('district_id') is-invalid @enderror" id="district_id" name="district_id" required>
                                    <option value="">— Select —</option>
                                    @foreach ($districts as $district)
                                        <option value="{{ $district->id }}" @selected(old('district_id') == $district->id)>{{ $district->name_en }} / {{ $district->name_hi }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="place" class="form-label">स्थान / Place</label>
                                <input type="text" class="form-control" id="place" name="place" value="{{ old('place') }}" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="pin" class="form-label">पिन / PIN</label>
                                <input type="text" class="form-control" id="pin" name="pin" value="{{ old('pin') }}" maxlength="6" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="department_email" class="form-label">विभाग का EMAIL (ONLY .GOV ID) / Department Contact Email</label>
                            <input type="email" class="form-control @error('department_email') is-invalid @enderror"
                                   id="department_email" name="department_email" value="{{ old('department_email') }}" required>
                            @error('department_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <hr>
                        <h2 class="h6">प्रशासकीय प्रमुख / Administrative Head (Level-2)</h2>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="ah_name" class="form-label">नाम / Name</label>
                                <input type="text" class="form-control" id="ah_name" name="ah_name" value="{{ old('ah_name') }}" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_designation_id" class="form-label">पदनाम / Designation</label>
                                <select class="form-select @error('ah_designation_id') is-invalid @enderror" id="ah_designation_id" name="ah_designation_id" required>
                                    <option value="">— Select —</option>
                                    @foreach ($designations as $designation)
                                        <option value="{{ $designation->id }}" @selected(old('ah_designation_id') == $designation->id)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_mobile" class="form-label">मोबाइल / Mobile</label>
                                <input type="text" class="form-control" id="ah_mobile" name="ah_mobile" value="{{ old('ah_mobile') }}" maxlength="10" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ah_email" class="form-label">ईमेल / Email (.gov.in)</label>
                                <input type="email" class="form-control @error('ah_email') is-invalid @enderror" id="ah_email" name="ah_email" value="{{ old('ah_email') }}" required>
                            </div>
                        </div>

                        <hr>
                        <h2 class="h6">DPC हेतु अधिकृत अधिकारी / Authorized Officer (Level-1)</h2>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="ao_name" class="form-label">नाम / Name</label>
                                <input type="text" class="form-control" id="ao_name" name="ao_name" value="{{ old('ao_name') }}" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_designation_id" class="form-label">पदनाम / Designation</label>
                                <select class="form-select @error('ao_designation_id') is-invalid @enderror" id="ao_designation_id" name="ao_designation_id" required>
                                    <option value="">— Select —</option>
                                    @foreach ($designations as $designation)
                                        <option value="{{ $designation->id }}" @selected(old('ao_designation_id') == $designation->id)>{{ $designation->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_mobile" class="form-label">मो. न. / Mobile</label>
                                <input type="text" class="form-control" id="ao_mobile" name="ao_mobile" value="{{ old('ao_mobile') }}" maxlength="10" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="ao_email" class="form-label">ईमेल / Email (.gov.in)</label>
                                <input type="email" class="form-control @error('ao_email') is-invalid @enderror" id="ao_email" name="ao_email" value="{{ old('ao_email') }}" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg px-4">Register / पंजीकृत करें</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>
@endsection
