<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>1. {{ __('dpcrules.when_heading') }}</strong></div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="effective_from_year">{{ __('dpcrules.effective_from_year') }}</label>
                <input type="number" min="2000" max="2100" class="form-control @error('effective_from_year') is-invalid @enderror" id="effective_from_year" name="effective_from_year" value="{{ old('effective_from_year', $year) }}" required>
                @error('effective_from_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-9"><div class="form-text">{{ __('dpcrules.when_help') }}</div></div>
        </div>
    </div>
</div>
