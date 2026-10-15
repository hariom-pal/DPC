@extends('layouts.admin')

@section('title', __('admin.dashboard.admin_dashboard'))

@section('content')
    <h1 class="h4 mb-1">{{ __('admin.dashboard.administration') }}</h1>
    <p class="text-muted">{{ __('admin.dashboard.master_data_user_accounts_and_the_portal_s_curre') }}</p>

    <div class="row g-3 mb-4">
        @php
            $tiles = [
                [__('admin.dashboard.tiles.departments.title'), $portal['departments_registered'].' / '.$portal['departments_total'], __('admin.dashboard.tiles.departments.text'), route('admin.departments.index')],
                [__('admin.dashboard.tiles.proposals.title'), $portal['proposals'], __('admin.dashboard.tiles.proposals.text', ['count' => $portal['proposals_with_commission']]), null],
                [__('admin.dashboard.tiles.sittings_open.title'), $portal['sittings_open'], __('admin.dashboard.tiles.sittings_open.text'), null],
                [__('admin.dashboard.tiles.sittings_concluded.title'), $portal['sittings_concluded'], __('admin.dashboard.tiles.sittings_concluded.text'), null],
                [__('admin.dashboard.tiles.circulations.title'), $portal['circulations'], __('admin.dashboard.tiles.circulations.text'), null],
                [__('admin.dashboard.tiles.accounts.title'), $accounts['total'], __('admin.dashboard.tiles.accounts.text', ['count' => $accounts['inactive']]), route('admin.users.index')],
            ];
        @endphp

        @foreach ($tiles as [$label, $value, $hint, $href])
            <div class="col-6 col-lg-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="fs-4 fw-semibold lh-1">
                            @if ($href)
                                <a href="{{ $href }}" class="text-decoration-none stretched-link">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </div>
                        <div class="small fw-semibold mt-2">{{ $label }}</div>
                        <div class="small text-muted">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-1">{{ __('admin.dashboard.master_data') }}</h2>
                    <p class="text-muted small">
                        {{ __('admin.dashboard.a_department_cannot_raise_a_dpc_until_these_are') }}
                    </p>
                    <div class="row g-2">
                        @foreach ($masters as $master)
                            <div class="col-6 col-md-4">
                                <a href="{{ $master['route'] }}" class="d-block border rounded p-2 text-decoration-none">
                                    <span class="fw-semibold">{{ $master['count'] }}</span>
                                    <span class="small text-muted d-block">{{ $master['label'] }}</span>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-1">
                        <a href="{{ route('admin.users.index') }}" class="text-decoration-none">{{ __('ui.user_management') }}</a>
                    </h2>
                    <p class="text-muted small">
                        {{ __('admin.dashboard.chairman_member_and_administrator_accounts_are_p') }}
                    </p>
                    <ul class="list-group list-group-flush small">
                        @foreach ($accounts['by_role'] as $role => $count)
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span>{{ $role }}</span>
                                <span class="fw-semibold">{{ $count }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('admin.integrations') }}" class="btn btn-outline-secondary btn-sm mt-3">
                        {{ __('admin.dashboard.integration_status') }}
                    </a>
                    {{-- M11. The trail has been written since M1 and had no
                         screen; the first person to want it is answering a
                         contested promotion. --}}
                    <a href="{{ route('admin.audit-trail.index') }}" class="btn btn-outline-secondary btn-sm mt-3">
                        {{ __('admin.dashboard.audit_trail') }}
                    </a>
                    <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm mt-3">
                        {{ __('ui.reports') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
