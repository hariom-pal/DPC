@extends('layouts.admin')

@section('title', __('admin.cgpsc_chairman.heading'))

@section('content')
    {{-- Who the CGPSC Chairman is, and was (change register of 2026-08-26, item 9).

         The tenure history is shown rather than only the serving Chairman, because
         the whole reason this is a dated chain is that a committee composed last
         year must still name the Chairman who sat on it. Hiding the history would
         hide the thing the design exists for. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ __('admin.cgpsc_chairman.heading') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.cgpsc_chairman.lead') }}</p>
        </div>
        <a href="{{ route('admin.cgpsc-chairman.create') }}" class="btn btn-success btn-sm">
            {{ __('admin.cgpsc_chairman.appoint') }}
        </a>
    </div>

    @include('partials.alerts')

    @if ($serving === null)
        <div class="alert alert-warning">{{ __('admin.cgpsc_chairman.no_chairman_recorded') }}</div>
    @else
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>{{ __('admin.cgpsc_chairman.serving') }}</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3 fw-normal">{{ __('admin.cgpsc_chairman.name') }}</dt>
                    <dd class="col-sm-9">{{ $serving->name }}</dd>

                    <dt class="col-sm-3 fw-normal">{{ __('admin.cgpsc_chairman.designation') }}</dt>
                    <dd class="col-sm-9">{{ $serving->designation ?? __('admin.cgpsc_chairman.not_recorded') }}</dd>

                    <dt class="col-sm-3 fw-normal">{{ __('ui.email') }}</dt>
                    <dd class="col-sm-9">{{ $serving->email ?? __('admin.cgpsc_chairman.not_recorded') }}</dd>

                    <dt class="col-sm-3 fw-normal">{{ __('ui.mobile') }}</dt>
                    <dd class="col-sm-9">{{ $serving->mobile ?? __('admin.cgpsc_chairman.not_recorded') }}</dd>

                    <dt class="col-sm-3 fw-normal">{{ __('admin.cgpsc_chairman.tenure') }}</dt>
                    <dd class="col-sm-9">{{ $serving->tenureLabel() }}</dd>

                    <dt class="col-sm-3 fw-normal">{{ __('ui.user') }}</dt>
                    <dd class="col-sm-9">{{ $serving->user?->name ?? __('admin.cgpsc_chairman.none') }}</dd>
                </dl>

                <a href="{{ route('admin.cgpsc-chairman.edit', $serving) }}" class="btn btn-outline-secondary btn-sm mt-3">
                    {{ __('ui.edit') }}
                </a>
                {{-- The Chairman's login password, reset here by the Administrator (2026-11-17). --}}
                @if ($serving->user)
                    <form method="POST" action="{{ route('admin.users.reset-password', $serving->user) }}" class="d-inline"
                          data-confirm="{{ __('admin.users.index.issue_a_new_temporary_password') }}">
                        @csrf
                        <button class="btn btn-outline-warning btn-sm mt-3" id="chairman-reset-password">{{ __('admin.users.index.reset_password') }}</button>
                    </form>
                @else
                    {{-- No login yet: create one from this record, so there is a password to reset. --}}
                    <form method="POST" action="{{ route('admin.cgpsc-chairman.account', $serving) }}" class="d-inline"
                          data-confirm="{{ __('admin.cgpsc_chairman.account_confirm') }}">
                        @csrf
                        <button class="btn btn-outline-primary btn-sm mt-3" id="chairman-create-account">{{ __('admin.cgpsc_chairman.account_create') }}</button>
                    </form>
                    <div class="form-text">{{ __('admin.cgpsc_chairman.account_hint') }}</div>
                @endif
            </div>
        </div>
    @endif

    @if ($history->count() > ($serving === null ? 0 : 1))
        <h2 class="h6">{{ __('admin.cgpsc_chairman.former') }}</h2>
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('admin.cgpsc_chairman.name') }}</th>
                            <th>{{ __('admin.cgpsc_chairman.designation') }}</th>
                            <th>{{ __('admin.cgpsc_chairman.tenure') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $chairman)
                            @continue($chairman->isServing())
                            <tr>
                                <td>{{ $chairman->name }}</td>
                                <td class="small">{{ $chairman->designation ?? __('admin.cgpsc_chairman.not_recorded') }}</td>
                                <td class="small">{{ $chairman->tenureLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
