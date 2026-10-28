{{-- Sign-in step 1: DigiLocker identity verification (2026-10-27). --}}
@extends('layouts.app')

@section('title', __('identity.title').' — '.__('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">{{ __('identity.title') }}</h1>
                    <p class="small">{{ __('identity.lead', ['name' => $user->name]) }}</p>
                    <ul class="small text-body-secondary">
                        <li>{{ __('identity.shares') }}</li>
                        <li>{{ __('identity.never_stored') }}</li>
                    </ul>

                    @include('partials.alerts')
                    @error('identity')<div class="alert alert-danger small" id="identity-error">{{ $message }}</div>@enderror

                    @if ($latest?->outcome === 'mismatch')
                        <div class="alert alert-warning small" id="identity-mismatch">
                            {{ __('identity.mismatch_fields', ['fields' => collect($latest->mismatched_fields)->map(fn ($f) => __('identity.field.'.$f))->implode(', ')]) }}
                            {{ __('identity.mismatch_next') }}
                        </div>
                    @endif

                    @if ($lockedUntil)
                        <div class="alert alert-danger small" id="identity-locked">{{ __('identity.locked', ['time' => $lockedUntil->format('d-m-Y H:i')]) }}</div>
                    @else
                        <form method="POST" action="{{ route('identity.start') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('identity.start') }}</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('login.cancel') }}" class="mt-2 text-center">
                        @csrf
                        <button type="submit" class="btn btn-link btn-sm">{{ __('identity.cancel') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
