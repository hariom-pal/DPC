@extends('layouts.app')

@section('title', __('ui.pre_dpc_proposals'))

@section('content')
    @php
        // The post's Vacancy Management, from an approved Pre-DPC (2026-10-30).
        $canVacancy = auth()->user()?->hasAnyRole([\App\Enums\Role::AuthorizedOfficer->value, \App\Enums\Role::AdministrativeHead->value]) ?? false;
    @endphp
    <div class="row justify-content-center">
        <div class="col-12">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">{{ __('predpc.index.pre_dpc_proposals') }}</h1>
                {{-- No "new" button: the Department Admin opens a Pre-DPC and
                     allots it (A1, 2026-10-26); it then appears here for its AO
                     and AH only. --}}
            </div>

            {{-- UAT, 2026-11-02: no sideways scrolling — the From/To pair once (it was
                 shown twice), version beside the number, Active beside the status, and a
                 compact Vacancy Management button. --}}
            <div class="card border-0 shadow-sm">
                <div>
                    <table class="table table-striped mb-0 align-middle" id="pre-dpc-list">
                        <thead>
                            {{-- One row per proposal, and a proposal is one exercise
                                 since 2026-08-24 (change items 8-10). "Active" is
                                 exercise-scoped: it means this is the Pre-DPC a DPC
                                 would be raised from for *this* pair and year. --}}
                            <tr><th>{{ __('predpc.index.pre_dpc_no') }}</th><th>{{ __('ui.year') }}</th><th>{{ __('predpc.index.promotion_from_post') }} → {{ __('predpc.index.promotion_to_post') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.approved') }}</th><th class="text-end">{{ __('ui.action') }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td class="text-nowrap"><code>{{ $proposal->pre_dpc_number ?? '—' }}</code> <span class="text-muted small">v{{ $proposal->version }}</span></td>
                                    <td>{{ $proposal->dpc_year ?? '—' }}</td>
                                    <td>
                                        {{ $proposal->promotionSources->map(fn ($source) => $source->promotionFromPost?->name)->filter()->implode(', ') ?: '—' }}
                                        <span class="text-muted">→</span>
                                        {{ $proposal->promotionToPost?->name ?? '—' }}
                                        @if ($proposal->government_order_number)
                                            <div class="text-muted small">{{ __('predpc.form.government_order_number') }}: {{ $proposal->government_order_number }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span>
                                        @if ($proposal->isActive())<span class="badge text-bg-primary">{{ __('ui.active') }}</span>@endif
                                    </td>
                                    <td class="text-nowrap">{{ $proposal->approved_at?->format('d-m-Y') ?? '—' }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex justify-content-end gap-1">
                                            <a href="{{ route('pre-dpc.show', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.open') }}</a>
                                            @if ($canVacancy && $proposal->status->value === 'approved' && $proposal->isActive() && $proposal->promotionToPost)
                                                <form method="POST" action="{{ route('vacancy-management.pre-dpc', $proposal) }}" class="d-inline">
                                                    @csrf
                                                    <button class="btn btn-primary btn-sm text-nowrap" data-pre-dpc-vacancy
                                                            title="{{ __('vacancymaster.pm.proceed', ['post' => $proposal->promotionToPost->name]) }}">{{ __('vacancymaster.pm.proceed_short') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('ui.no_proposals_yet') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">{{ $proposals->links() }}</div>
        </div>
    </div>
@endsection
