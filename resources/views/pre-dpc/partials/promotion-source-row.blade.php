{{--
    One Promotion From Post on a Pre-DPC.

    `$index` is a row number rather than an id: the rows are replaced wholesale on
    save, and `__INDEX__` in the template below is substituted by the Add button.

    **The Pay Scale and Class shown are this post's own** — not the post being
    promoted into. Promotion eligibility is judged on the officer's current post,
    so the feeder's scale and class are what a reader needs beside it.
--}}
@php
    $postId = (int) ($row['promotion_from_post_id'] ?? 0);
    $attributes = $postId > 0 ? ($derived[$postId] ?? null) : null;
@endphp

<tr class="promotion-source-row">
    <td>
        <label class="visually-hidden" for="source-{{ $index }}-post">{{ __('predpc.form.promotion_from_post') }}</label>
        <select class="form-select form-select-sm source-post @error("promotion_sources.{$index}.promotion_from_post_id") is-invalid @enderror"
                id="source-{{ $index }}-post"
                name="promotion_sources[{{ $index }}][promotion_from_post_id]" required>
            <option value="">{{ __('ui.select') }}</option>
            @foreach ($posts as $post)
                <option value="{{ $post->id }}" @selected($postId === $post->id)>{{ $post->name }}</option>
            @endforeach
        </select>
        @error("promotion_sources.{$index}.promotion_from_post_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    {{-- Filled in by public/js/forms/pre-dpc-exercise.js as the post changes, and
         rendered server-side here so it is correct with JavaScript disabled. --}}
    <td class="small source-attributes">
        @if ($attributes === null)
            <span class="text-body-secondary">{{ __('predpc.form.select_a_post_first') }}</span>
        @else
            <span class="d-block">{{ __('ui.pay_scale') }}: <strong>{{ $attributes->payScaleLabel ?? '—' }}</strong></span>
            <span class="d-block">{{ __('ui.class') }}: <strong>{{ $attributes->classLevel->label() }}</strong></span>
        @endif
    </td>

    <td>
        <label class="visually-hidden" for="source-{{ $index }}-share">{{ __('predpc.form.share') }}</label>
        <div class="input-group input-group-sm">
            <input type="number" min="0" max="100" step="1" inputmode="numeric" data-whole-number
                   class="form-control @error("promotion_sources.{$index}.promotion_percentage") is-invalid @enderror"
                   id="source-{{ $index }}-share"
                   name="promotion_sources[{{ $index }}][promotion_percentage]"
                   value="{{ (fn ($v) => is_numeric($v) && (float) $v == (int) $v ? (int) $v : $v)($row['promotion_percentage'] ?? '100') }}" required>
            <span class="input-group-text">%</span>
        </div>
        @error("promotion_sources.{$index}.promotion_percentage")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td>
        <label class="visually-hidden" for="source-{{ $index }}-experience">{{ __('predpc.form.min_experience') }}</label>
        <div class="input-group input-group-sm">
            <input type="number" min="0" max="40" step="1" inputmode="numeric" data-whole-number
                   class="form-control @error("promotion_sources.{$index}.min_experience_years") is-invalid @enderror"
                   id="source-{{ $index }}-experience"
                   name="promotion_sources[{{ $index }}][min_experience_years]"
                   value="{{ $row['min_experience_years'] ?? 5 }}" required>
            <select class="form-select" name="promotion_sources[{{ $index }}][experience_unit]"
                    aria-label="{{ __('predpc.form.experience_unit') }}" required>
                @foreach ($experienceUnits as $unit)
                    <option value="{{ $unit->value }}" @selected(($row['experience_unit'] ?? 'years') === $unit->value)>{{ $unit->label() }}</option>
                @endforeach
            </select>
        </div>
        @error("promotion_sources.{$index}.min_experience_years")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    {{-- Item 11 (2026-08-26): a feeder may accept any of several qualifications.
         Selecting none is a real answer — a feeder may impose no qualification
         beyond holding the Promotion From Post — so there is no "none" option to
         choose; an empty selection is that answer. --}}
    <td>
        <label class="visually-hidden" for="source-{{ $index }}-qualification">{{ __('predpc.form.min_qualification') }}</label>
        @php $selectedQualifications = array_map('intval', (array) ($row['qualification_ids'] ?? [])); @endphp
        {{-- A dropdown of checkboxes with a filter box, not a ctrl-click list:
             public/js/multiselect-dropdown.js enhances any <select multiple>
             carrying `data-multiselect`. The select itself is unchanged — same
             name, same `[]`, same options, same posted payload — so with the
             script blocked this is still the multi-select it always was. --}}
        <select class="form-select form-select-sm" id="source-{{ $index }}-qualification"
                name="promotion_sources[{{ $index }}][qualification_ids][]"
                size="4"
                multiple
                data-multiselect
                data-multiselect-none="{{ __('ui.multiselect_none') }}"
                data-multiselect-count="{{ __('ui.multiselect_count') }}"
                data-multiselect-no-match="{{ __('ui.multiselect_no_match') }}"
                data-multiselect-clear="{{ __('ui.clear') }}"
                data-multiselect-done="{{ __('ui.multiselect_done') }}"
                data-multiselect-search="{{ __('ui.multiselect_search') }}">
            @foreach ($qualifications as $qualification)
                <option value="{{ $qualification->id }}"
                    @selected(in_array($qualification->id, $selectedQualifications, true))>{{ $qualification->name_en }}</option>
            @endforeach
        </select>
        <div class="form-text small">{{ __('predpc.form.qualifications_hint') }}</div>
        @error("promotion_sources.{$index}.qualification_ids")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>

    <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm remove-promotion-source"
                aria-label="{{ __('predpc.form.remove_source') }}">{{ __('ui.remove') }}</button>
    </td>
</tr>
