{{-- Date of birth and gender, as on the officer's Aadhaar (2026-10-27).
     Expects: $dob (Y-m-d or null), $gender (M/F/T or null); optional $locked, $required (default true). --}}
<div class="row g-2 mb-3">
    <div class="col-sm-6">
        <label for="date_of_birth" class="form-label">{{ __('identity.field.date_of_birth') }}</label>
        <input type="date" class="form-control @error('date_of_birth') is-invalid @enderror" id="date_of_birth" name="date_of_birth"
               value="{{ old('date_of_birth', $dob) }}" max="{{ now()->subDay()->toDateString() }}" @if ($required ?? true) required @endif @if (! empty($locked)) readonly @endif>
        @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-sm-6">
        <label for="gender" class="form-label">{{ __('identity.field.gender') }}</label>
        @if (! empty($locked))
            <input type="hidden" name="gender" value="{{ $gender }}">
            <input type="text" class="form-control" value="{{ $gender ? __('identity.gender.'.$gender) : '' }}" readonly>
        @else
            <select class="form-select @error('gender') is-invalid @enderror" id="gender" name="gender" @if ($required ?? true) required @endif data-searchable="off">
                <option value="">{{ __('ui.select') }}</option>
                @foreach (['M', 'F'] as $g)
                    <option value="{{ $g }}" @selected(old('gender', $gender) === $g)>{{ __('identity.gender.'.$g) }}</option>
                @endforeach
            </select>
            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @endif
    </div>
    <div class="col-12 form-text">{{ __('identity.as_on_aadhaar') }}</div>
</div>
