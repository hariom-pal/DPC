@extends('layouts.app')

@section('title', __('ui.pre_dpc_proposals'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">{{ __('predpc.index.pre_dpc_proposals') }}</h1>
                {{-- No "new" button: the Department Admin opens a Pre-DPC and
                     allots it (A1, 2026-10-26); it then appears here for its AO
                     and AH only. --}}
            </div>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            {{-- One row per proposal, and a proposal is one exercise
                                 since 2026-08-24 (change items 8-10). "Active" is
                                 exercise-scoped: it means this is the Pre-DPC a DPC
                                 would be raised from for *this* pair and year. --}}
                            <tr><th>{{ __('predpc.index.pre_dpc_no') }}</th><th>{{ __('ui.year') }}</th><th>{{ __('predpc.index.exercise') }}</th><th>{{ __('ui.ver') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.active') }}</th><th>{{ __('ui.approved') }}</th><th class="text-end">{{ __('ui.action') }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td class="text-nowrap"><code>{{ $proposal->pre_dpc_number ?? '—' }}</code></td>
                                    <td>{{ $proposal->dpc_year ?? '—' }}</td>
                                    <td>
                                        {{ $proposal->promotionFromPost?->name ?? '—' }}
                                        <span class="text-muted">→</span>
                                        {{ $proposal->promotionToPost?->name ?? '—' }}
                                        @if ($proposal->government_order_number)
                                            <div class="text-muted small">{{ __('ui.go_number', ['number' => $proposal->government_order_number]) }}</div>
                                        @endif
                                    </td>
                                    <td>v{{ $proposal->version }}</td>
                                    <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                                    <td>@if ($proposal->isActive())<span class="badge text-bg-primary">{{ __('ui.active') }}</span>@endif</td>
                                    <td>{{ $proposal->approved_at?->format('d-m-Y') ?? '—' }}</td>
                                    <td class="text-end"><a href="{{ route('pre-dpc.show', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.open') }}</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('ui.no_proposals_yet') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">{{ $proposals->links() }}</div>
        </div>
    </div>
@endsection
