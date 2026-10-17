{{-- The order that authorises a new version, and its PDF. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>{{ $step }}. {{ __('dpcrules.authority_heading') }}</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="order_number">{{ __('dpcrules.order_number') }}</label>
                <input type="text" maxlength="120" class="form-control @error('order_number') is-invalid @enderror" id="order_number" name="order_number" value="{{ old('order_number') }}" required>
                @error('order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="order_date">{{ __('dpcrules.order_date') }}</label>
                <input type="date" class="form-control @error('order_date') is-invalid @enderror" id="order_date" name="order_date" value="{{ old('order_date') }}" required>
                @error('order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5">
                <label class="form-label" for="document">{{ __('dpcrules.document') }}</label>
                <input type="file" accept="application/pdf" class="form-control @error('document') is-invalid @enderror" id="document" name="document" required>
                @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if ($errors->any() && ! $errors->has('document'))
                    <div class="form-text text-danger">{{ __('dpcrules.document_reselect') }}</div>
                @endif
            </div>
            <div class="col-12">
                <label class="form-label" for="remarks">{{ __('dpcrules.remarks') }}</label>
                <textarea class="form-control @error('remarks') is-invalid @enderror" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>
