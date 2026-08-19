{{-- PPT S50 — "LOGIN PAGE FOR INDIVIDUAL BEING CONSIDER FOR DPC"

     The slide shows AADHAR NO + OTP, with NAME AS ON AADHAR CARD and DATE OF
     JOINING CURRENT CADRE beneath. Aadhaar was replaced project-wide by decision
     Q1, and the officer has no account to hold a password — so the fields here
     are the facts their department recorded, and which fields those are is
     configuration (`dpc.self_inspection.authentication.factors`, BD-19).

     The field list is therefore rendered from $factors rather than written out:
     adding a factor to configuration must add it to this form and to the
     matcher together, or the screen would refuse an officer who answered
     correctly. --}}

@extends('layouts.portal')

@section('title', 'Self-Inspection — Identify yourself')

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center g-4 align-items-start">

                <div class="col-lg-5 order-lg-1 order-2">
                    <div class="gov-card p-4 h-100">
                        <h2 class="h6 mb-3">Why these details</h2>
                        <p class="small text-body-secondary">
                            You do not have an account on this portal — you are not a departmental user.
                            So instead of a password, you confirm facts that your department recorded
                            about you on the proposal it forwarded.
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
                            <h1 class="h4 mb-1">Self-Inspection</h1>
                            <p class="text-body-secondary small mb-0">
                                स्व-निरीक्षण · Confirm your identity
                            </p>
                        </div>

                        @include('partials.alerts')

                        @if (! $enabled || $pending !== null)
                            <div class="alert alert-secondary mb-0" role="status">
                                Self-Inspection is not available at the moment.
                                <a href="{{ route('self-inspection.landing') }}">More information</a>.
                            </div>
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
                                    Continue / आगे बढ़ें
                                </button>
                            </form>
                        @endif

                        <hr class="my-4">

                        <p class="small text-body-secondary mb-0">
                            Details not matching? They must match what your <strong>department</strong>
                            recorded, which may differ from your own papers — that is itself worth raising
                            with them.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
