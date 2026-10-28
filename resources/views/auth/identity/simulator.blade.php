{{-- Simulated DigiLocker (local and testing only; refused in production). --}}
@extends('layouts.app')

@section('title', __('identity.simulator.title'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card border-warning shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 mb-2">{{ __('identity.simulator.title') }}</h1>
                    <div class="alert alert-warning small">{{ __('identity.simulator.warning') }}</div>
                    <form method="POST" action="{{ route('identity.simulator.submit') }}">
                        @csrf
                        <input type="hidden" name="state" value="{{ $state }}">
                        <div class="mb-2">
                            <label for="sim_name" class="form-label">{{ __('identity.field.name') }}</label>
                            <input type="text" class="form-control" id="sim_name" name="name" maxlength="255">
                        </div>
                        <div class="mb-2">
                            <label for="sim_dob" class="form-label">{{ __('identity.field.date_of_birth') }}</label>
                            <input type="date" class="form-control" id="sim_dob" name="date_of_birth">
                        </div>
                        <div class="mb-2">
                            <label for="sim_gender" class="form-label">{{ __('identity.field.gender') }}</label>
                            <select class="form-select" id="sim_gender" name="gender" data-searchable="off">
                                <option value="M">{{ __('identity.gender.M') }}</option>
                                <option value="F">{{ __('identity.gender.F') }}</option>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="aadhaar_verified" value="1" id="sim_aadhaar" checked>
                            <label class="form-check-label small" for="sim_aadhaar">{{ __('identity.simulator.aadhaar_verified') }}</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="decision" value="allow" class="btn btn-primary">{{ __('identity.simulator.allow') }}</button>
                            <button type="submit" name="decision" value="deny" class="btn btn-outline-secondary" formnovalidate>{{ __('identity.simulator.deny') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
