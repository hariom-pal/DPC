{{-- A system-issued temporary password, shown once (business decision, 2026-10-26).

     Rendered as the direct response to the POST that created it — never flashed,
     never put in a URL or browser storage — and served with Cache-Control: no-store.
     Only its hash exists afterwards, so leaving this page loses it for good; a new
     reset issues a new one. The previous password is never shown, because it is
     never stored. --}}
@extends($layout)

@section('title', __('account.issued.title'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 mb-3">
                        {{ $reason === 'reset' ? __('account.issued.reset_heading', ['name' => $user->name]) : __('account.issued.created_heading', ['name' => $user->name]) }}
                    </h1>

                    <div class="alert alert-warning small">
                        {{ __('account.issued.shown_once') }}
                        @if ($reason === 'reset')
                            <strong class="d-block mt-1">{{ __('account.issued.previous_invalid') }}</strong>
                        @endif
                    </div>

                    <dl class="row small mb-3">
                        <dt class="col-sm-4">{{ __('account.issued.login_id') }}</dt>
                        <dd class="col-sm-8">{{ $user->email }} @if ($user->mobile) / {{ $user->mobile }} @endif</dd>
                        <dt class="col-sm-4">{{ __('account.issued.temporary_password') }}</dt>
                        <dd class="col-sm-8 d-flex align-items-center gap-2 flex-wrap">
                            <code class="fs-5" id="issued-password">{{ $password }}</code>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-target="issued-password"
                                    data-copied-label="{{ __('account.issued.copied') }}">{{ __('account.issued.copy') }}</button>
                        </dd>
                    </dl>

                    <p class="small text-body-secondary mb-3">{{ __('account.issued.must_change') }}</p>

                    <a href="{{ $back }}" class="btn btn-primary btn-sm">{{ __('account.issued.done') }}</a>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/copy-password.js') }}" defer></script>
@endpush
