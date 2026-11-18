@extends('layouts.admin')

@section('title', __('dpc.committee.account.list_title'))

@section('content')
    {{-- Every serving DPC committee member and the state of the login (2026-11-18). --}}
    <h1 class="h4 mb-1">{{ __('dpc.committee.account.list_title') }}</h1>
    <p class="text-muted small mb-3">{{ __('dpc.committee.account.list_lead') }}</p>

    @include('partials.alerts')
    @error('committee_account')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

    <form method="GET" action="{{ route('admin.committee-members.index') }}" class="d-flex gap-2 mb-2" id="committee-member-search">
        <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" style="max-width: 22rem" placeholder="{{ __('dpc.committee.account.search') }}" aria-label="{{ __('dpc.committee.account.search') }}">
        <button class="btn btn-outline-primary btn-sm">{{ __('ui.search') }}</button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle small" id="committee-members">
                <thead>
                    <tr>
                        <th>{{ __('ui.dpc_p_no') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.designation') }}</th><th>{{ __('ui.department') }}</th>
                        <th>{{ __('ui.email') }}</th><th>{{ __('ui.mobile') }}</th><th>{{ __('dpc.committee.account.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr data-member-account="{{ $member->id }}">
                            <td class="font-monospace">{{ $member->proposal?->dpc_code ?? '—' }}</td>
                            <td>{{ $member->name }}</td>
                            <td>{{ $member->designation ?? '—' }}</td>
                            <td>{{ $member->user?->activeAssignment?->department?->name ?? '—' }}</td>
                            <td>{{ $member->email ?? '—' }}</td>
                            <td>{{ $member->mobile ?? '—' }}</td>
                            <td>
                                @if ($member->user)
                                    <span class="badge text-bg-success">{{ __('dpc.committee.account.linked') }}</span>
                                @else
                                    <span class="badge text-bg-warning">{{ __('dpc.committee.account.none') }}</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($member->proposal)
                                    @if ($member->user)
                                        <form method="POST" class="d-inline" action="{{ route('admin.committee-members.reset-password', [$member->proposal, $member]) }}"
                                              data-confirm="{{ __('dpc.committee.account.reset_confirm') }}">
                                            @csrf
                                            <button class="btn btn-outline-warning btn-sm" data-member-reset="{{ $member->id }}">{{ __('admin.users.index.reset_password') }}</button>
                                        </form>
                                    @else
                                        <form method="POST" class="d-inline" action="{{ route('admin.committee-members.account', [$member->proposal, $member]) }}">
                                            @csrf
                                            <button class="btn btn-outline-primary btn-sm" data-member-create-login="{{ $member->id }}">{{ __('dpc.committee.account.create') }}</button>
                                        </form>
                                    @endif
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.committee-members.replace.form', [$member->proposal, $member]) }}">{{ __('dpc.committee.replace_member') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">{{ __('dpc.sections.committee.no_committee_members_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-2">{{ $members->links() }}</div>
@endsection
