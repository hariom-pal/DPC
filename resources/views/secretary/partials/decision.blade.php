{{-- The Secretary, CGPSC's decision (2026-11-14): approve with an eSign, forwarding the
     DPC to the Chairman, or return it to the AO with the reason and a supporting document. --}}
<div id="secretary-decision">
    <p class="small mb-2">{{ __('dpc.secretary.final_note') }}</p>
    @error('workflow')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('esign.show', ['kind' => 'dpc-secretary-approve', 'uuid' => $proposal->uuid]) }}" class="btn btn-success" data-esign id="dpc-secretary-approve">{{ __('esign.button.secretary_approve') }}</a>
    </div>

    <form method="POST" action="{{ route('secretary.return', $proposal) }}" enctype="multipart/form-data" class="border rounded p-2"
          data-confirm="{{ __('dpc.secretary.return_confirm') }}" id="secretary-return">
        @csrf
        <h3 class="h6">{{ __('dpc.secretary.return_heading') }}</h3>
        <div class="row g-2">
            <div class="col-md-7">
                <label class="form-label small" for="secretary-return-remarks">{{ __('dpc.secretary.remarks_label') }}</label>
                <textarea class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" id="secretary-return-remarks" name="return_remarks" rows="2" maxlength="1000" required>{{ old('return_remarks') }}</textarea>
                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="secretary-return-document">{{ __('dpc.secretary.document_label') }}</label>
                <input type="file" accept="application/pdf" class="form-control form-control-sm @error('return_document') is-invalid @enderror" id="secretary-return-document" name="return_document" required>
                @error('return_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12"><button class="btn btn-outline-warning btn-sm">{{ __('dpc.secretary.return_button') }}</button></div>
        </div>
    </form>
</div>
