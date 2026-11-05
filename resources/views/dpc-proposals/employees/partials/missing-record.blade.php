{{-- A mandatory CR or Property Return marked Missing (2026-11-05): the reason and the
     supporting document, required here and recorded as the missing-record
     justification (rule 3.2). Shown when `$when` is answered; `$years`, when given,
     narrows it to the years the promotion rule makes mandatory. --}}
<div class="border rounded bg-light p-2 mt-2" data-show-when="{{ $when }}" @if ($years !== null) data-only-years="{{ implode(',', $years) }}" @endif data-missing-record>
    <div class="small fw-semibold mb-1">{{ __('dpc.employee_details.missing_record.heading') }}</div>
    <div class="small text-muted mb-2">{{ __('dpc.employee_details.missing_record.help') }}</div>
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label small" for="{{ $prefix }}-missing-reason">{{ __('dpc.employee_details.missing_record.reason') }}</label>
            <select class="form-select form-select-sm @error('missing_reason_id') is-invalid @enderror" id="{{ $prefix }}-missing-reason" name="missing_reason_id" data-required-when-shown>
                <option value="">{{ __('ui.select') }}</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason->id }}" @selected((string) old('missing_reason_id') === (string) $reason->id)>{{ $reason->label() }}</option>
                @endforeach
            </select>
            @error('missing_reason_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label small" for="{{ $prefix }}-missing-document">{{ __('dpc.employee_details.missing_record.document') }}</label>
            <input type="file" accept="application/pdf,.pdf" class="form-control form-control-sm @error('missing_document') is-invalid @enderror" id="{{ $prefix }}-missing-document" name="missing_document" data-required-when-shown>
            @error('missing_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label small" for="{{ $prefix }}-missing-second">{{ __('dpc.employee_details.missing_record.second_document') }}</label>
            <input type="file" accept="application/pdf,.pdf" class="form-control form-control-sm @error('missing_second_document') is-invalid @enderror" id="{{ $prefix }}-missing-second" name="missing_second_document">
            @error('missing_second_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label small" for="{{ $prefix }}-missing-remarks">{{ __('dpc.employee_details.missing_record.remarks') }}</label>
            <textarea class="form-control form-control-sm @error('missing_remarks') is-invalid @enderror" id="{{ $prefix }}-missing-remarks" name="missing_remarks" rows="2" maxlength="2000">{{ old('missing_remarks') }}</textarea>
            @error('missing_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>
