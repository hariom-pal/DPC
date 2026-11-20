@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start')
    <div class="gov-card p-4" style="max-width: 520px">
        <h2 class="h6">{{ __('candidate.verification.login.identify_heading') }}</h2>
        <p class="small text-body-secondary">{{ __('candidate.verification.login.identify_lead') }}</p>
        <form method="POST" action="{{ route('candidate-verification.identify.store') }}" id="candidate-identify">
            @csrf
            <label class="form-label" for="employee_number">{{ __('candidate.verification.login.employee_number') }}</label>
            <input type="text" class="form-control mb-3" id="employee_number" name="employee_number" maxlength="60" required>
            <button class="btn btn-primary">{{ __('candidate.verification.login.verify') }}</button>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
