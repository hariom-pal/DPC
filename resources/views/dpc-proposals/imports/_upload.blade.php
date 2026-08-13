{{--
    Excel upload widget for a proposal section (PPT S16–S18) — M4 Phase 6.
    Uploading only stages a preview; nothing is written until the officer
    confirms on the preview screen.

    Expects: $proposal, $template (ImportTemplate).
--}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-1">Upload from Excel</h2>
        <p class="text-muted small mb-2">
            Upload the <strong>{{ $template->label() }}</strong> template instead of typing rows by hand.
            You will see a preview with any errors before anything is saved, and committing
            <strong>replaces</strong> this section.
        </p>

        @error('file')<div class="alert alert-danger py-2 mb-2">{{ $message }}</div>@enderror

        <form method="POST" action="{{ route('dpc-proposals.imports.store', $proposal) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="template" value="{{ $template->value }}">
            <div class="col-md-7">
                <label class="form-label small">Spreadsheet file</label>
                <input type="file" class="form-control form-control-sm" name="file" required
                       accept=".xlsx,.xls,.csv,.txt">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Upload &amp; Preview</button>
            </div>
        </form>

        <details class="mt-2">
            <summary class="small text-muted">Expected columns</summary>
            <p class="small text-muted mt-1 mb-0">
                {{ implode(' · ', $template->expectedHeaders()) }}
                <br>Dates in <code>DD-MM-YYYY</code>. Category is the code (UR / SC / ST / OBC).
                Column order does not matter; unrecognised columns are ignored.
            </p>
        </details>
    </div>
</div>
