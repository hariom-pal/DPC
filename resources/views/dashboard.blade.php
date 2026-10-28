@extends('layouts.app')

@section('title', __('ui.dashboard.title').' — '.__('messages.app_name'))

@section('content')
    @include('partials.alerts')

    <div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1>{{ __('ui.dashboard.welcome', ['name' => $user->name]) }}</h1>
            <p>{{ $user->designation?->name ?? __('ui.layouts.app.e_dpc_portal') }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($user->getRoleNames() as $role)
                <span class="badge text-bg-primary text-uppercase">{{ str_replace('_', ' ', $role) }}</span>
            @endforeach
        </div>
    </div>

    {{-- What is waiting, by role. Counts only; every card links to the screen
         that governs the work. --}}
    @foreach ($summaries as $group)
        <div class="mb-3 mb-lg-4">
            @include('partials.summary-cards', ['group' => $group])
        </div>
    @endforeach

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
                        <dt class="col-5 text-body-secondary fw-normal">{{ __('ui.department') }}</dt>
                        <dd class="col-7 fw-semibold">{{ $user->department->name }}</dd>
                    @endif
                    <dt class="col-5 text-body-secondary fw-normal">{{ __('ui.mobile') }}</dt>
                    <dd class="col-7 fw-semibold">{{ $user->mobile ?? '—' }}</dd>
                    <dt class="col-5 text-body-secondary fw-normal">{{ __('ui.email') }}</dt>
                    <dd class="col-7 fw-semibold text-break">{{ $user->email ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        {{-- Actions available to this user, by role --}}
        <div class="col-lg-8">
            <div class="gov-card p-4 h-100">
                <h2 class="h6 mb-1">{{ __('ui.dashboard.your_workspace') }}</h2>
                <p class="small text-body-secondary mb-3">{{ __('ui.dashboard.only_the_areas_your_role_permits_are_shown') }}</p>

                @php
                    /*
                     * Tiles are per role, and the wording is per role too: the
                     * same screen is a different job to a Chairman and to a
                     * Member, and a tile that promises an action the reader
                     * cannot take is the problem this list exists to avoid.
                     */
                    $isAO = $user->hasRole(\App\Enums\Role::AuthorizedOfficer->value);
                    $isAH = $user->hasRole(\App\Enums\Role::AdministrativeHead->value);
                    $isChairman = $user->hasRole(\App\Enums\Role::Chairman->value);
                    $isMember = $user->hasRole(\App\Enums\Role::Member->value);

                    $tiles = [];

                    // Department Admin (2026-10-28): the department's posts and its DPCs.
                    if ($user->hasRole(\App\Enums\Role::DepartmentAdmin->value)) {
                        $tiles[] = ['paperless', __('allotments.open'), __('allotments.open_lead'), route('department.allotments.pre-dpc.create')];
                        $tiles[] = ['workflow', __('allotments.list.pre-dpc.title'), __('allotments.list.pre-dpc.lead'), route('department.allotments.index', ['view' => 'pre-dpc'])];
                        $tiles[] = ['workflow', __('allotments.list.running.title'), __('allotments.list.running.lead'), route('department.allotments.index')];
                        $tiles[] = ['workflow', __('allotments.list.closed.title'), __('allotments.list.closed.lead'), route('department.allotments.index', ['view' => 'closed'])];
                        $tiles[] = ['workflow', __('ui.layouts.admin.nav.department_posts'), __('allotments.posts_tile'), route('department.posts.index')];
                    }

                    if ($isAO || $isAH) {
                        $tiles[] = ['paperless', __('ui.dashboard.tiles.pre_dpc.title'),
                            $isAO
                                ? __('ui.dashboard.tiles.pre_dpc.authorized_officer')
                                : __('ui.dashboard.tiles.pre_dpc.administrative_head'),
                            route('pre-dpc.index')];
                        $tiles[] = ['workflow', __('ui.dashboard.tiles.dpc.title'),
                            $isAO
                                ? __('ui.dashboard.tiles.dpc.authorized_officer')
                                : __('ui.dashboard.tiles.dpc.administrative_head'),
                            route('dpc-proposals.index')];
                        $tiles[] = ['circulate', __('ui.dashboard.tiles.circulation.title'),
                            __('ui.dashboard.tiles.circulation.text'), route('dpc-circulations.index')];
                        // M11. The three upload templates. Reached from here
                        // rather than from the upload screens themselves, which
                        // are M4's and frozen — see ImportTemplateController.
                        $tiles[] = ['folder', __('ui.dashboard.tiles.templates.title'),
                            __('ui.dashboard.tiles.templates.text'), route('dpc-import-templates.index')];
                    }

                    /*
                     * Allotment is the Chairman's alone (PPT S28, DpcAllotmentPolicy::allot).
                     * A Member reaches the DPCs allotted to *them* through
                     * Schedules, so the tile would only have led them to a
                     * screen whose one action is hidden from them.
                     */
                    if ($isChairman) {
                        $tiles[] = ['workflow', __('ui.dashboard.tiles.allotments.title'),
                            __('ui.dashboard.tiles.allotments.text'), route('cgpsc.allotments.index')];
                    }

                    if ($isChairman || $isMember) {
                        $tiles[] = ['flow-check', __('ui.dashboard.tiles.schedules.title'),
                            $isMember && ! $isChairman
                                ? __('ui.dashboard.tiles.schedules.member')
                                : __('ui.dashboard.tiles.schedules.chairman'),
                            route('cgpsc.schedules.index')];
                        $tiles[] = ['audit', __('ui.dashboard.tiles.proceedings.title'),
                            $isMember && ! $isChairman
                                ? __('ui.dashboard.tiles.proceedings.member')
                                : __('ui.dashboard.tiles.proceedings.chairman'),
                            route('cgpsc.proceedings.index')];
                        $tiles[] = ['circulate', __('ui.dashboard.tiles.circulations.title'),
                            __('ui.dashboard.tiles.circulations.text'), route('cgpsc.circulations.index')];
                    }

                    /*
                     | The sitting, for anybody seated on a DPC committee (business
                     | instruction, 2026-09-07).
                     |
                     | Keyed on the **seat**, not on a role: a committee member holds
                     | none, which is why every other tile above is invisible to
                     | them and why this screen exists at all. Without this tile they
                     | would sign in successfully and find an empty workspace.
                     */
                    if (app(\App\Domain\Proceeding\Services\CommitteeSittingService::class)->sittingsFor($user)->isNotEmpty()) {
                        $tiles[] = ['audit', __('proceeding.sitting.title'),
                            __('proceeding.sitting.lead'), route('sitting.index')];
                    }

                    if ($user->hasRole(\App\Enums\Role::Admin->value)) {
                        $tiles[] = ['admin', __('ui.dashboard.tiles.administration.title'),
                            __('ui.dashboard.tiles.administration.text'), route('admin.dashboard')];
                    }
                @endphp

                @if ($tiles === [])
                    @include('partials.empty-state', [
                        'title' => __('ui.dashboard.no_workspace.title'),
                        'text' => __('ui.dashboard.no_workspace.text'),
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
