@extends('layouts.admin')

@section('title', __('admin.seniority_links.heading'))

@section('content')
    {{-- 2026-11-22: one CGPSC Seniority List link per DPC, shown to its candidates. --}}
    <h1 class="h4 mb-1">{{ __('admin.seniority_links.heading') }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.seniority_links.lead') }}</p>

    <form method="GET" action="{{ route('admin.seniority-links.index') }}" class="row g-2 align-items-end mb-3" id="seniority-links-filter">
        <div class="col-12 col-md-6">
            <label for="q" class="form-label small mb-1">{{ __('admin.seniority_links.search') }}</label>
            <input type="search" name="q" id="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ __('admin.seniority_links.search_placeholder') }}">
        </div>
        <div class="col-auto">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="missing" value="1" id="missing" @checked($missingOnly)>
                <label class="form-check-label small" for="missing">{{ __('admin.seniority_links.missing_only', ['count' => $pending]) }}</label>
            </div>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm">{{ __('admin.seniority_links.filter') }}</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="seniority-links">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('admin.seniority_links.dpc') }}</th>
                        <th>{{ __('admin.seniority_links.department') }}</th>
                        <th>{{ __('admin.seniority_links.post') }}</th>
                        <th>{{ __('admin.seniority_links.status') }}</th>
                        <th style="min-width: 22rem;">{{ __('admin.seniority_links.link') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposals as $proposal)
                        @php($bag = $errors->getBag('link'.$proposal->id))
                        <tr data-proposal="{{ $proposal->id }}">
                            <td><code>{{ $proposal->dpc_code ?? '—' }}</code>@if ($proposal->dpc_year)<div class="small text-muted">{{ $proposal->dpc_year }}</div>@endif</td>
                            <td>{{ $proposal->department?->name ?? '—' }}</td>
                            <td class="small">{{ $proposal->promotionFromPost?->name ?? '—' }} → {{ $proposal->promotionToPost?->name ?? '—' }}</td>
                            <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admin.seniority-links.update', $proposal) }}" class="d-flex gap-1">
                                    @csrf
                                    @method('PUT')
                                    <input type="url" name="cgpsc_seniority_list_url" value="{{ $bag->any() ? old('cgpsc_seniority_list_url') : $proposal->cgpsc_seniority_list_url }}"
                                           class="form-control form-control-sm @if ($bag->any()) is-invalid @endif" placeholder="{{ __('admin.seniority_links.placeholder') }}" maxlength="500"
                                           aria-label="{{ __('admin.seniority_links.link') }}: {{ $proposal->dpc_code }}">
                                    <button class="btn btn-outline-primary btn-sm">{{ __('ui.save') }}</button>
                                </form>
                                @if ($bag->any())<div class="text-danger small">{{ $bag->first('cgpsc_seniority_list_url') }}</div>@endif
                                @if (! $proposal->cgpsc_seniority_list_url)<span class="badge text-bg-warning mt-1">{{ __('admin.seniority_links.pending') }}</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ __('admin.seniority_links.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $proposals->links() }}</div>
@endsection
