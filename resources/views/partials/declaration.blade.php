{{-- A mandatory declaration (MoM of 2026-10-29), in Hindi and English whatever the
     screen's language. Expects $key (config dpc.declarations.*); $name defaults to
     "declaration". --}}
@php($name = $name ?? 'declaration')
<div class="border rounded p-3 bg-body-tertiary" data-declaration="{{ $key }}">
    <div class="form-check">
        <input class="form-check-input @error($name) is-invalid @enderror" type="checkbox" name="{{ $name }}" value="1" id="{{ $name }}" required @checked(old($name))>
        <label class="form-check-label small" for="{{ $name }}">
            <span class="d-block mb-1" lang="hi">{{ config('dpc.declarations.'.$key.'.hi') }}</span>
            <span class="d-block" lang="en">{{ config('dpc.declarations.'.$key.'.en') }}</span>
        </label>
        @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
