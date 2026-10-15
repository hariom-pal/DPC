{{-- PPT S50 — "LOGIN PAGE FOR INDIVIDUAL BEING CONSIDER FOR DPC"

     The slide shows AADHAR NO + OTP, with NAME AS ON AADHAR CARD and DATE OF
     JOINING PROMOTION TO POST beneath. Aadhaar was replaced project-wide by decision
     Q1, and the officer has no account to hold a password — so the fields here
     are the facts their department recorded, and which fields those are is
     configuration (`dpc.self_inspection.authentication.factors`, BD-19).

     The field list is therefore rendered from $factors rather than written out:
     adding a factor to configuration must add it to this form and to the
     matcher together, or the screen would refuse an officer who answered
     correctly. --}}

@extends('layouts.portal')

@section('title', __('selfinspection.identify.self_inspection_identify_yourself'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center g-4 align-items-start">

                <div class="col-lg-5 order-lg-1 order-2">
                    <div class="gov-card p-4 h-100">
                        <h2 class="h6 mb-3">{{ __('selfinspection.identify.why_these_details') }}</h2>
                        <p class="small text-body-secondary">
                            {{ __('selfinspection.identify.you_do_not_have_an_account_on_this_portal_you_ar') }}
                        </p>
                        <ul class="list-unstyled d-grid gap-3 mb-0">
                            @foreach ([
                                ['lock', 'All details must match', 'Every field below must match your department\'s record exactly. Nothing is matched partially.'],
                                ['audit', 'Attempts are recorded and limited', 'Each attempt is recorded, and repeated failures are blocked for a period.'],
                                ['eye', 'You see only your own record', 'A match opens your row alone. No other officer\'s data is reachable from this screen.'],
                            ] as [$icon, $title, $text])
                                <li class="d-flex gap-3">
                                    <span class="gov-icon flex-shrink-0">
                                        @include('partials.icon', ['name' => $icon, 'size' => 20])
                                    </span>
                                    <span>
                                        <strong class="d-block small">{{ $title }}</strong>
                                        <span class="small text-body-secondary">{{ $text }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-lg-5 order-lg-2 order-1">
                    <div class="gov-card p-4 p-lg-5">
                        <div class="text-center mb-4">
                            <h1 class="h4 mb-1">{{ __('selfinspection.identify.self_inspection') }}</h1>
                            <p class="text-body-secondary small mb-0">
                                {{ __('selfinspection.identify.confirm_your_identity') }}
                            </p>
                        </div>

                        @include('partials.alerts')

                        @if (! $enabled || $pending !== null)
                            <div class="alert alert-secondary mb-0" role="status">
{{ __('selfinspection.identify.not_available') }}
                                <a href="{{ route('self-inspection.landing') }}">{{ __('selfinspection.identify.more_information') }}</a>.
                            </div>
                        {{-- Two forms, one screen (business instruction, 2026-09-07).

                             Under the OTP driver an officer proves they hold the
                             mobile number their department recorded, which is a
                             stronger claim than reciting three facts that circulate
                             on a seniority list. The factor form at the end is kept
                             and still renders under `identity_factors`. --}}
                        @elseif ($usesOtp && $awaitingCode)
                            <form method="POST" action="{{ route('self-inspection.identify.store') }}">
                                @csrf

                                <p class="small text-body-secondary">
                                    {{ __('selfinspection.otp.code_sent_to', ['mobile' => $sentTo]) }}
                                </p>

                                <div class="mb-3">
                                    <label for="code" class="form-label">{{ __('selfinspection.otp.code_label') }}</label>
                                    <input type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                                           class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                                           id="code" name="code" autofocus required>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">{{ __('selfinspection.otp.code_hint') }}</div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    {{ __('selfinspection.identify.continue') }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('self-inspection.identify.store') }}" class="mt-2">
                                @csrf
                                {{-- Starting again clears the number in the session, so a
                                     code is always verified against the number it went to. --}}
                                <button type="submit" name="restart" value="1" class="btn btn-link btn-sm w-100">
                                    {{ __('selfinspection.otp.wrong_number') }}
                                </button>
                            </form>
                        @elseif ($usesOtp)
                            <form method="POST" action="{{ route('self-inspection.identify.store') }}">
                                @csrf

                                <div class="mb-3">
                                    <label for="mobile" class="form-label">{{ __('selfinspection.otp.mobile_label') }}</label>
                                    <input type="text" inputmode="numeric" maxlength="10" autocomplete="tel-national"
                                           class="form-control form-control-lg @error('mobile') is-invalid @enderror"
                                           id="mobile" name="mobile" value="{{ old('mobile') }}" autofocus required>
                                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">{{ __('selfinspection.otp.mobile_hint') }}</div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    {{ __('selfinspection.otp.send_code') }}
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('self-inspection.identify.store') }}">
                                @csrf

                                @foreach ($factors as $factor)
                                    <div class="mb-3">
                                        <label for="{{ $factor->value }}" class="form-label">
                                            {{ $factor->label() }}
                                        </label>
                                        <input type="{{ $factor->isDate() ? 'date' : 'text' }}"
                                               class="form-control @error($factor->value) is-invalid @enderror"
                                               id="{{ $factor->value }}" name="{{ $factor->value }}"
                                               value="{{ old($factor->value) }}"
                                               autocomplete="off"
                                               @if ($loop->first) autofocus @endif
                                               required>
                                        @error($factor->value)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach

                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    {{ __('selfinspection.identify.continue') }}
                                </button>
                            </form>
                        @endif

                        <hr class="my-4">

                        <p class="small text-body-secondary mb-0">
{!! __('selfinspection.identify.details_not_matching') !!}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
