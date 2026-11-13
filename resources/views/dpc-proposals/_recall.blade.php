{{-- The AO takes the DPC back to correct it, from the AH or from CGPSC until the result
     is approved (MoM of 12 November 2026). Expects: $proposal. --}}
@php
    $recallRefusal = app(\App\Domain\Dpc\Services\DpcProposalService::class)->recallRefusal($proposal, auth()->user());
@endphp
@if ($recallRefusal === null)
    <div class="card border-warning shadow-sm mb-3" id="dpc-recall">
        <div class="card-body">
            <h2 class="h6 mb-1">{{ __('dpc.recall.heading') }}</h2>
            <p class="small text-muted mb-2">{{ __('dpc.recall.lead') }}</p>
            @error('recall')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
            <form method="POST" action="{{ route('dpc-proposals.recall', $proposal) }}" data-confirm="{{ __('dpc.recall.confirm') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-9">
                    <label class="form-label small" for="recall-remarks">{{ __('dpc.recall.remarks_label') }}</label>
                    <input type="text" maxlength="1000" class="form-control form-control-sm @error('recall_remarks') is-invalid @enderror" id="recall-remarks" name="recall_remarks" value="{{ old('recall_remarks') }}" required>
                    @error('recall_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">{{ __('dpc.recall.button') }}</button></div>
            </form>
        </div>
    </div>
@endif
