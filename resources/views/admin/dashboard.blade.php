@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
    <h1 class="h4 mb-1">Administration</h1>
    <p class="text-muted">Master data, user accounts and the portal's current workload.</p>

    <div class="row g-3 mb-4">
        @php
            $tiles = [
                ['Departments registered', $portal['departments_registered'].' / '.$portal['departments_total'], 'Of the department master', route('admin.departments.index')],
                ['DPC proposals', $portal['proposals'], $portal['proposals_with_commission'].' forwarded to the Commission', null],
                ['Sittings open', $portal['sittings_open'], 'Being conducted right now', null],
                ['Sittings concluded', $portal['sittings_concluded'], 'Result approved by the Commission', null],
                ['Circulations', $portal['circulations'], 'Held promotions taken up for resolution', null],
                ['Accounts', $accounts['total'], $accounts['inactive'].' deactivated', route('admin.users.index')],
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
                    <h2 class="h6 mb-1">Master Data</h2>
                    <p class="text-muted small">
                        A department cannot raise a DPC until these are populated. A zero here is the reason
                        a later screen has nothing to offer.
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
                        <a href="{{ route('admin.users.index') }}" class="text-decoration-none">User Management</a>
                    </h2>
                    <p class="text-muted small">
                        Chairman, Member and administrator accounts are provisioned here. Departmental
                        Authorized Officers and Administrative Heads arrive through registration.
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
                        Integration status
                    </a>
                    {{-- M11. The trail has been written since M1 and had no
                         screen; the first person to want it is answering a
                         contested promotion. --}}
                    <a href="{{ route('admin.audit-trail.index') }}" class="btn btn-outline-secondary btn-sm mt-3">
                        Audit trail
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
