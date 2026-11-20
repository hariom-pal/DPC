<div class="py-4" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
    <div class="container" style="max-width: 980px">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h5 mb-0">{{ __('candidate.verification.portal') }}</h1>
            @if ($signedIn ?? false)
                <form method="POST" action="{{ route('candidate-verification.logout') }}" class="m-0">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm">{{ __('candidate.verification.login.logout') }}</button>
                </form>
            @endif
        </div>
        @include('partials.alerts')
        @if ($errors->any())
            <div class="alert alert-danger py-2 small" role="alert">
                @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
            </div>
        @endif
