@extends('layouts.app')

@section('title', __('dpc.secretary.title'))

{{-- The Secretary, CGPSC (2026-11-14): every department's DPC the AH approved. --}}
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('dpc.secretary.title') }}</h1>
            <p class="text-muted small mb-3">{{ __('dpc.secretary.lead') }}</p>

            @error('workflow')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm mb-3" id="secretary-pending">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.secretary.pending') }} <span class="badge text-bg-primary">{{ $pending->count() }}</span></h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th>{{ __('ui.sn') }}</th>
                                <th>{{ __('dpc.secretary.department') }}</th>
                                <th>{{ __('dpc.secretary.dpc') }}</th>
                                <th>{{ __('ui.post_from') }}</th>
                                <th>{{ __('ui.post_to') }}</th>
                                <th>{{ __('dpc.secretary.ah_approved') }}</th>
                                <th class="text-end">{{ __('ui.actions') }}</th>
                            </tr></thead>
                            <tbody>
                                @forelse ($pending as $index => $proposal)
                                    <tr data-secretary-pending="{{ $proposal->uuid }}">
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $proposal->department?->name }}</td>
                                        <td class="font-monospace">{{ $proposal->citation() }}</td>
                                        <td>{{ $proposal->promotionFromPost?->name }}</td>
                                        <td>{{ $proposal->promotionToPost?->name }}</td>
                                        <td class="small">{{ $proposal->approved_at?->format('d-m-Y H:i') }} — {{ $proposal->approver?->name }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-primary btn-sm" href="{{ route('dpc-proposals.review', [$proposal, 1]) }}">{{ __('dpc.secretary.open') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-muted text-center">{{ __('dpc.secretary.none') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100" id="secretary-approved">
                        <div class="card-body">
                            <h2 class="h6">{{ __('dpc.secretary.decided') }}</h2>
                            <table class="table table-sm align-middle mb-0 small">
                                <thead><tr><th>{{ __('dpc.secretary.dpc') }}</th><th>{{ __('dpc.secretary.department') }}</th><th>{{ __('dpc.secretary.approved_at') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($decided as $proposal)
                                        <tr>
                                            <td class="font-monospace"><a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_code ?? '—' }}</a></td>
                                            <td>{{ $proposal->department?->name }}</td>
                                            <td>{{ $proposal->secretary_approved_at?->format('d-m-Y H:i') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted">{{ __('dpc.secretary.none_decided') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100" id="secretary-returned">
                        <div class="card-body">
                            <h2 class="h6">{{ __('dpc.secretary.returned_list') }}</h2>
                            <table class="table table-sm align-middle mb-0 small">
                                <thead><tr><th>{{ __('dpc.secretary.dpc') }}</th><th>{{ __('dpc.secretary.returned_at') }}</th><th>{{ __('dpc.secretary.reason') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($returned as $revision)
                                        <tr>
                                            <td class="font-monospace">
                                                @if ($revision->proposal)
                                                    <a href="{{ route('dpc-proposals.show', $revision->proposal) }}">{{ $revision->proposal->dpc_code ?? '—' }}</a>
                                                    <span class="text-muted">R{{ $revision->revision_number }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $revision->returned_at?->format('d-m-Y H:i') }}</td>
                                            <td>
                                                {{ $revision->return_reason }}
                                                @if ($revision->return_document_path && $revision->proposal)
                                                    — <a href="{{ route('dpc-proposals.revisions.return-document', [$revision->proposal, $revision]) }}" target="_blank" rel="noopener">{{ __('dpc.secretary.document') }}</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted">{{ __('dpc.secretary.none_decided') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
