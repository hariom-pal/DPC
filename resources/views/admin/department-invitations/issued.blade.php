{{-- The registration link of an invitation just issued, shown once (2026-10-26).

     The direct response to the invite/resend POST, served no-store: the token is
     stored only as a hash, so this page is the only place the link exists in
     clear. Lets a department be invited while no mail server delivers. --}}
@extends('layouts.admin')

@section('title', __('invitations.issued.title'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 mb-3">
                        {{ __($resent ? 'invitations.issued.heading_resent' : 'invitations.issued.heading', ['department' => $invitation->department->name]) }}
                    </h1>

                    <div class="alert alert-warning small">
                        {{ __('invitations.issued.shown_once') }}
                        @if ($resent)
                            <strong class="d-block mt-1">{{ __('invitations.issued.previous_invalid') }}</strong>
                        @endif
                    </div>

                    @if ($mailed)
                        <div class="alert alert-success small" id="invitation-mailed">{{ __('invitations.issued.mailed', ['email' => $invitation->email]) }}</div>
                    @else
                        <div class="alert alert-danger small" id="invitation-not-mailed">{{ __('invitations.issued.not_mailed', ['email' => $invitation->email]) }}</div>
                    @endif

                    <dl class="row small mb-3">
                        <dt class="col-sm-4">{{ __('invitations.issued.email') }}</dt>
                        <dd class="col-sm-8">{{ $invitation->email }}</dd>
                        <dt class="col-sm-4">{{ __('invitations.issued.expires') }}</dt>
                        <dd class="col-sm-8">{{ $invitation->expires_at?->format('d-m-Y H:i') }}</dd>
                        <dt class="col-sm-4">{{ __('invitations.issued.link') }}</dt>
                        <dd class="col-sm-8">
                            <code class="d-block text-break mb-2" id="invitation-link">{{ $link }}</code>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-target="invitation-link"
                                    data-copied-label="{{ __('invitations.issued.copied') }}">{{ __('invitations.issued.copy') }}</button>
                        </dd>
                    </dl>

                    <a href="{{ route('admin.department-invitations.index') }}" class="btn btn-primary btn-sm">{{ __('invitations.issued.done') }}</a>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/copy-password.js') }}" defer></script>
@endpush
