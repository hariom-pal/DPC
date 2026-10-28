@extends('layouts.app')
@section('title', __('esign.simulator.title'))
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <h1 class="h4 mb-1">{{ __('esign.simulator.title') }}</h1>
            <div class="alert alert-warning small">{{ __('esign.simulator.warning') }}</div>

            <form method="POST" action="{{ route('esign.simulator.submit') }}" class="card border-0 shadow-sm" id="esign-simulator">
                @csrf
                <input type="hidden" name="reference" value="{{ $reference }}">
                <div class="card-body">
                    <label for="name" class="form-label small fw-semibold">{{ __('esign.simulator.name') }}</label>
                    <input type="text" class="form-control" id="name" name="name" value="{{ $signer->name }}" required maxlength="255">
                    <div class="form-text">{{ __('esign.simulator.name_help') }}</div>
                </div>
                <div class="card-footer bg-transparent d-flex gap-2">
                    <button type="submit" name="decision" value="sign" class="btn btn-success btn-sm">{{ __('esign.simulator.sign') }}</button>
                    <button type="submit" name="decision" value="cancel" class="btn btn-outline-danger btn-sm" formnovalidate>{{ __('esign.simulator.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
