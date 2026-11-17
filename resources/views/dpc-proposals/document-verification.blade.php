@extends('layouts.app')
@section('title', __('dpc.document_verification.title'))
@section('content')
    {{-- Rule 4.4: the Administrative Head's verification of every officer's documents,
         category by category, with Verify all (2026-11-16). --}}
    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('dpc.document_verification.title') }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('dpc.document_verification.title') }}</h1>
            <p class="text-muted mb-0" id="verification-summary">{{ __('dpc.document_verification.summary', ['pending' => $pending, 'total' => $total]) }}</p>
        </div>
        @if ($canVerify && $pending > 0)
            <form method="POST" action="{{ route('dpc-proposals.document-verification.verify', $proposal) }}" data-confirm="{{ __('dpc.document_verification.confirm_all', ['count' => $pending]) }}">
                @csrf
                <button class="btn btn-success" id="verify-all-proposal-documents">{{ __('dpc.document_verification.verify_all', ['count' => $pending]) }}</button>
            </form>
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <p class="small text-muted">{{ __('dpc.employee_details.verification.verify_all_help') }}</p>

    @forelse ($categories as $kind => $documents)
        @php
            $label = \App\Domain\Dpc\Enums\EvaluationDocumentKind::from($kind)->label();
            $categoryPending = $documents->whereNull('ah_verified_at')->count();
        @endphp
        <div class="card border-0 shadow-sm mb-3" id="category-{{ $kind }}" data-document-category="{{ $kind }}">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h6 mb-0">
                        {{ $label }}
                        <span class="badge {{ $categoryPending > 0 ? 'text-bg-warning' : 'text-bg-success' }}" data-category-pending="{{ $kind }}">
                            {{ $categoryPending > 0 ? __('dpc.document_verification.category_pending', ['pending' => $categoryPending, 'total' => $documents->count()]) : __('dpc.document_verification.category_done') }}
                        </span>
                    </h2>
                    @if ($canVerify && $categoryPending > 0)
                        <form method="POST" action="{{ route('dpc-proposals.document-verification.verify', $proposal) }}">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $kind }}">
                            <button class="btn btn-outline-success btn-sm" data-verify-category="{{ $kind }}">{{ __('dpc.document_verification.verify_category', ['category' => $label]) }}</button>
                        </form>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle small mb-0">
                        <thead><tr>
                            <th>{{ __('dpc.document_verification.employee') }}</th>
                            <th>{{ __('ui.year') }}</th>
                            <th>{{ __('dpc.document_verification.document') }}</th>
                            <th>{{ __('dpc.document_verification.uploaded') }}</th>
                            <th>{{ __('dpc.employee_details.verification.ao') }}</th>
                            <th>{{ __('dpc.document_verification.ah') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($documents as $document)
                                @php
                                    $row = $document->row;
                                @endphp
                                <tr data-verification-document="{{ $document->uuid }}">
                                    <td><a href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}">{{ $row->sn }}. {{ $row->name }}</a></td>
                                    <td>{{ $document->year ?? '—' }}@if ($document->part) <span class="text-muted">({{ __('dpc.employee_details.cr.cr_number', ['number' => $document->part]) }})</span>@endif</td>
                                    <td>
                                        <a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $document]) }}" target="_blank" rel="noopener">{{ $document->original_name }}</a>
                                        @if ($document->remarks)<div class="text-muted">{{ $document->remarks }}</div>@endif
                                    </td>
                                    <td>{{ $document->uploader?->name ?? '—' }}<div class="text-muted">{{ $document->uploaded_at->format('d-m-Y H:i') }}</div></td>
                                    <td>{!! $document->ao_verified_at ? '✓ '.e($document->ao_verified_at->format('d-m-Y')) : '—' !!}</td>
                                    <td>
                                        @if ($document->ah_verified_at)
                                            <span class="badge text-bg-success">✓ {{ $document->ah_verified_at->format('d-m-Y') }}</span>
                                        @elseif ($canVerify)
                                            <form method="POST" action="{{ route('dpc-proposals.document-verification.verify', $proposal) }}">
                                                @csrf
                                                <input type="hidden" name="document" value="{{ $document->uuid }}">
                                                <button class="btn btn-outline-success btn-sm">{{ __('dpc.employee_details.verification.verify') }}</button>
                                            </form>
                                        @else
                                            <span class="badge text-bg-warning">{{ __('dpc.document_verification.awaiting') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-info">{{ __('dpc.document_verification.none') }}</div>
    @endforelse

    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('dpc.document_verification.back') }}</a>
@endsection
