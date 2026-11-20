@extends('layouts.portal')
@section('title', __('candidate.verification.review.esign_heading'))
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    <div class="gov-card p-3" style="max-width: 520px" id="esign-simulator">
        <h2 class="h6">{{ __('candidate.verification.review.esign_heading') }}</h2>
        <form method="POST" action="{{ route('candidate-verification.esign.submit', $participant->uuid) }}">
            @csrf
            <label class="form-label small" for="name">{{ __('candidate.verification.review.esign_name') }}</label>
            <input type="text" class="form-control mb-2" id="name" name="name" value="{{ $participant->row->name }}" maxlength="255">
            <button class="btn btn-primary" name="decision" value="sign">{{ __('candidate.verification.review.esign_sign') }}</button>
            <button class="btn btn-outline-secondary" name="decision" value="cancel" formnovalidate>{{ __('candidate.verification.review.esign_cancel') }}</button>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
