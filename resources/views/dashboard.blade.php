@extends('layouts.app')

@section('title', 'Dashboard — ' . __('messages.app_name'))

@section('content')
    @include('partials.alerts')

    <div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1>Welcome, {{ $user->name }}</h1>
            <p>{{ $user->designation?->name ?? 'e-DPC Portal' }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($user->getRoleNames() as $role)
                <span class="badge text-bg-primary text-uppercase">{{ str_replace('_', ' ', $role) }}</span>
            @endforeach
        </div>
    </div>

    <div class="row g-3 g-lg-4">
        {{-- Identity card --}}
        <div class="col-lg-4">
            <div class="gov-card p-4 h-100">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="gov-icon lg">@include('partials.icon', ['name' => 'roles', 'size' => 26])</div>
                    <div>
                        <h2 class="h6 mb-0">{{ $user->name }}</h2>
                        <div class="small text-body-secondary">{{ $user->designation?->name ?? '—' }}</div>
                    </div>
                </div>
                <dl class="row small mb-0">
                    @if ($user->department)
                        <dt class="col-5 text-body-secondary fw-normal">Department</dt>
                        <dd class="col-7 fw-semibold">{{ $user->department->name }}</dd>
                    @endif
                    <dt class="col-5 text-body-secondary fw-normal">Mobile</dt>
                    <dd class="col-7 fw-semibold">{{ $user->mobile ?? '—' }}</dd>
                    <dt class="col-5 text-body-secondary fw-normal">Email</dt>
                    <dd class="col-7 fw-semibold text-break">{{ $user->email ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        {{-- Actions available to this user, by role --}}
        <div class="col-lg-8">
            <div class="gov-card p-4 h-100">
                <h2 class="h6 mb-1">Your workspace</h2>
                <p class="small text-body-secondary mb-3">Only the areas your role permits are shown.</p>

                @php
                    $tiles = [];
                    if ($user->hasAnyRole([\App\Enums\Role::AuthorizedOfficer->value, \App\Enums\Role::AdministrativeHead->value])) {
                        $tiles[] = ['paperless', 'Pre-DPC Proposals', 'Prepare and submit the preliminary proposal.', route('pre-dpc.index')];
                        $tiles[] = ['workflow', 'DPC Proposals', 'The full proposal — committee, vacancies, seniority, chart.', route('dpc-proposals.index')];
                        $tiles[] = ['circulate', 'Circulation DPC', 'Resolve a promotion the Commission reserved.', route('dpc-circulations.index')];
                    }
                    if ($user->hasAnyRole([\App\Enums\Role::Chairman->value, \App\Enums\Role::Member->value])) {
                        $tiles[] = ['workflow', 'Allotments', 'Allot a forwarded DPC to a Member.', route('cgpsc.allotments.index')];
                        $tiles[] = ['flow-check', 'Schedules', 'Accept an allotment and schedule the sitting.', route('cgpsc.schedules.index')];
                        $tiles[] = ['audit', 'Proceedings', 'Conduct the sitting, calculate and approve.', route('cgpsc.proceedings.index')];
                        $tiles[] = ['circulate', 'Circulations', 'Consent to and sign circulation notes.', route('cgpsc.circulations.index')];
                    }
                    if ($user->hasRole(\App\Enums\Role::Admin->value)) {
                        $tiles[] = ['admin', 'Administration', 'Master data, users and integration status.', route('admin.dashboard')];
                    }
                @endphp

                @if ($tiles === [])
                    @include('partials.empty-state', [
                        'title' => 'No workspace assigned yet',
                        'text' => 'Your account has no role that opens a workspace. Contact your department\'s nodal officer or the Commission.',
                        'icon' => 'help',
                    ])
                @else
                    <div class="row g-3">
                        @foreach ($tiles as [$icon, $title, $text, $href])
                            <div class="col-sm-6">
                                <a href="{{ $href }}" class="gov-card p-3 h-100">
                                    <div class="d-flex align-items-start gap-3">
                                        <span class="gov-icon flex-shrink-0">
                                            @include('partials.icon', ['name' => $icon, 'size' => 20])
                                        </span>
                                        <span>
                                            <strong class="d-block small">{{ $title }}</strong>
                                            <span class="small text-body-secondary">{{ $text }}</span>
                                        </span>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
