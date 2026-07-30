@extends('layouts.app')

@section('title', 'OTP Verification — ' . __('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3 text-center">OTP Verification / ओ.टी.पी. सत्यापन</h1>

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('verify.store') }}">
                        @csrf
                        @if ($user->email)
                            <div class="mb-3">
                                <label for="email_code" class="form-label">
                                    Email OTP (sent to {{ $user->email }})
                                </label>
                                <input type="text" class="form-control" id="email_code" name="email_code"
                                       inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required>
                            </div>
                        @endif
                        <div class="mb-3">
                            <label for="mobile_code" class="form-label">
                                Mobile OTP (sent to {{ $user->mobile }})
                            </label>
                            <input type="text" class="form-control" id="mobile_code" name="mobile_code"
                                   inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Verify / सत्यापित करें</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
