@extends('layouts.app')
@section('title', __('dpc.record_gaps.title'))
@section('content')
    @php
        $crGapYears = $row->recordGaps->where('kind', 'cr')->pluck('year')->all();
        $loyaltyGapYears = $row->recordGaps->where('kind', 'loyalty')->pluck('year')->all();
        $gradedYears = $row->crGrades->filter(fn ($g) => filled($g->grade))->pluck('year')->sort()->values();
        $missingLoyaltyYears = $gradedYears->reject(
            fn (int $year) => $row->loyaltyFlags->firstWhere('year', $year)?->flag === true,
        )->values();
    @endphp

    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a>
            </li>
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.evaluation-chart', $proposal) }}">{{ __('dpc.record_gaps.evaluation_chart') }}</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('dpc.record_gaps.breadcrumb') }}</li>
        </ol>
    </nav>

    <h1 class="h4 mb-1">{{ __('dpc.record_gaps.title') }}</h1>
    <p class="text-muted mb-3">
        {{ __('dpc.record_gaps.subtitle', ['sn' => $row->sn, 'name' => $row->name]) }}
    </p>

    <div class="alert alert-info">
        <strong>{{ __('dpc.record_gaps.rule_heading') }}</strong>
        <p class="mb-0 small">{{ __('dpc.record_gaps.rule_body', ['count' => $requiredCrCount]) }}</p>
    </div>

    {{-- What the chart is currently short of, so the officer is not left to work it out. --}}
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.record_gaps.cr_status_heading') }}</h2>
                    <p class="mb-0">
                        {{ __('dpc.record_gaps.cr_status', [
                            'recorded' => $gradedYears->count(),
                            'required' => $requiredCrCount,
                        ]) }}
                    </p>
                    @if ($gradedYears->count() < $requiredCrCount)
                        <p class="small text-muted mb-0 mt-1">
                            {{ __('dpc.record_gaps.cr_shortfall', [
                                'shortfall' => $requiredCrCount - $gradedYears->count(),
                                'justified' => count($crGapYears),
                            ]) }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.record_gaps.loyalty_status_heading') }}</h2>
                    @if ($missingLoyaltyYears->isEmpty())
                        <p class="mb-0">{{ __('dpc.record_gaps.loyalty_all_present') }}</p>
                    @else
                        <p class="mb-0">
                            {{ __('dpc.record_gaps.loyalty_missing', ['years' => $missingLoyaltyYears->implode(', ')]) }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Already recorded --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">{{ __('dpc.record_gaps.recorded_heading') }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('dpc.record_gaps.record_column') }}</th>
                        <th>{{ __('ui.year') }}</th>
                        <th>{{ __('dpc.record_gaps.reason_column') }}</th>
                        <th>{{ __('dpc.record_gaps.remarks_column') }}</th>
                        <th>{{ __('dpc.record_gaps.document_column') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($row->recordGaps->sortBy(['kind', 'year']) as $gap)
                        <tr>
                            <td>
                                {{ $gap->kind === 'cr'
                                    ? __('dpc.record_gaps.kind_cr')
                                    : __('dpc.record_gaps.kind_loyalty') }}
                            </td>
                            <td>{{ $gap->year }}</td>
                            <td>{{ $gap->reason?->label() }}</td>
                            <td class="small">{{ $gap->remarks ?: '—' }}</td>
                            <td>
                                @if ($gap->hasDocument())
                                    <a href="{{ route('dpc-proposals.record-gaps.download', [$proposal, $row, $gap]) }}">
                                        {{ $gap->original_name }}
                                    </a>
                                    {{-- The hash is what makes the file evidence rather than a copy. --}}
                                    <div class="text-muted small font-monospace">{{ Str::limit($gap->sha256, 16, '…') }}</div>
                                    @if ($gap->hasSecondDocument())
                                        <a href="{{ route('dpc-proposals.record-gaps.download', [$proposal, $row, $gap, 'part' => 'second']) }}">{{ $gap->second_original_name }}</a>
                                    @elseif ($gap->reason?->requiresSecondDocument())
                                        <span class="badge text-bg-danger">{{ __('dpc.record_gaps.second_document_missing', ['document' => $gap->reason->secondDocumentLabel()]) }}</span>
                                    @endif
                                @else
                                    <span class="badge text-bg-danger">{{ __('dpc.record_gaps.no_document') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <form method="POST"
                                      action="{{ route('dpc-proposals.record-gaps.destroy', [$proposal, $row, $gap]) }}"
                                      data-confirm="{{ __('dpc.record_gaps.withdraw_confirm') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('dpc.record_gaps.withdraw') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                {{ __('dpc.record_gaps.none_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Record another --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">{{ __('dpc.record_gaps.form_heading') }}</h2>
        </div>
        <div class="card-body">
            @if ($reasons->isEmpty())
                <div class="alert alert-warning mb-0">{{ __('dpc.record_gaps.no_reasons_configured') }}</div>
            @else
                <form method="POST" enctype="multipart/form-data"
                      action="{{ route('dpc-proposals.record-gaps.store', [$proposal, $row]) }}"
                      data-record-gap-form
                      data-remarks-reasons="{{ $reasons->where('requires_remarks', true)->pluck('id')->join(',') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="kind">{{ __('dpc.record_gaps.record_column') }}</label>
                            <select class="form-select @error('kind') is-invalid @enderror" id="kind" name="kind" required>
                                <option value="cr" @selected(old('kind') === 'cr')>{{ __('dpc.record_gaps.kind_cr') }}</option>
                                <option value="loyalty" @selected(old('kind') === 'loyalty')>{{ __('dpc.record_gaps.kind_loyalty') }}</option>
                            </select>
                            @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="year">{{ __('ui.year') }}</label>
                            <input type="number" min="1950" max="2200"
                                   class="form-control @error('year') is-invalid @enderror"
                                   id="year" name="year" value="{{ old('year') }}" required>
                            <div class="form-text">{{ __('dpc.record_gaps.year_help') }}</div>
                            @error('year')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="record_reason_id">{{ __('dpc.record_gaps.reason_column') }}</label>
                            <select class="form-select @error('record_reason_id') is-invalid @enderror"
                                    id="record_reason_id" name="record_reason_id" required>
                                <option value="">{{ __('dpc.record_gaps.choose_reason') }}</option>
                                @foreach ($reasons as $reason)
                                    <option value="{{ $reason->id }}"
                                            data-requires-remarks="{{ $reason->requires_remarks ? '1' : '0' }}"
                                            @selected((int) old('record_reason_id') === $reason->id)>
                                        {{ $reason->label() }}@if ($reason->documentLabel()) — {{ $reason->documentLabel() }}@if ($reason->requiresSecondDocument()) + {{ $reason->secondDocumentLabel() }}@endif @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('record_reason_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="remarks">
                            {{ __('dpc.record_gaps.remarks_column') }}
                            <span class="text-danger d-none" data-remarks-required-marker>*</span>
                        </label>
                        <textarea class="form-control @error('remarks') is-invalid @enderror"
                                  id="remarks" name="remarks" rows="2">{{ old('remarks') }}</textarea>
                        <div class="form-text">{{ __('dpc.record_gaps.remarks_help') }}</div>
                        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="document">{{ __('dpc.record_gaps.document_column') }}</label>
                        <input type="file" accept="application/pdf"
                               class="form-control @error('document') is-invalid @enderror"
                               id="document" name="document" required>
                        <div class="form-text">{{ __('dpc.record_gaps.document_help') }}</div>
                        @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Rule 3.2: a reason that needs two documents (a suspension's reinstatement order). --}}
                    <div class="mb-3">
                        <label class="form-label" for="second_document">{{ __('dpc.record_gaps.second_document') }}</label>
                        <input type="file" accept="application/pdf"
                               class="form-control @error('second_document') is-invalid @enderror"
                               id="second_document" name="second_document">
                        <div class="form-text">{{ __('dpc.record_gaps.second_document_help') }}</div>
                        @error('second_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-success">{{ __('dpc.record_gaps.save') }}</button>
                    <a href="{{ route('dpc-proposals.evaluation-chart', $proposal) }}"
                       class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </form>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="@versioned('js/forms/dpc-record-gaps.js')" defer></script>
@endpush
