{{--
    One row of the feeder grid.

    Shared by the saved rows and by the <template> the Add button clones, so the
    two can never drift apart — a blank row is this same markup with $row null and
    $index the literal string __INDEX__.

    $index          int|string  array index, or '__INDEX__' in the template
    $row            array|null  existing values, keyed as the request expects
    $posts         Collection  every active post (filtered client-side by department)
    $qualifications Collection  Active qualifications only
    $units          array       ExperienceUnit cases
--}}
<tr class="promotion-source-row">
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-post">{{ __('admin.recruitment_rules.partials.feeder_row.promotion_from_post') }}</label>
        <select class="form-select form-select-sm post-select @error("feeders.{$index}.promotion_from_post_id") is-invalid @enderror"
                id="feeder-{{ $index }}-post"
                name="feeders[{{ $index }}][promotion_from_post_id]" required
                data-searchable data-searchable-placeholder="{{ __('ui.search_posts') }}">
            <option value="">{{ __('ui.select') }}</option>
            @foreach ($posts as $post)
                <option value="{{ $post->id }}"
                        data-department="{{ $post->department_id }}"
                        @selected(($row['promotion_from_post_id'] ?? null) == $post->id)>
                    {{ $post->name }} ({{ $post->class_level->label() }})
                </option>
            @endforeach
        </select>
        @error("feeders.{$index}.promotion_from_post_id")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-share">{{ __('admin.recruitment_rules.partials.feeder_row.promotion_share_percentage') }}</label>
        <div class="input-group input-group-sm">
            <input type="number" step="0.01" min="0" max="100"
                   class="form-control share-input @error("feeders.{$index}.promotion_percentage") is-invalid @enderror"
                   id="feeder-{{ $index }}-share"
                   name="feeders[{{ $index }}][promotion_percentage]"
                   value="{{ $row['promotion_percentage'] ?? '' }}" required>
            <span class="input-group-text">%</span>
        </div>
        @error("feeders.{$index}.promotion_percentage")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    <td>
        {{-- Value and unit together: "5" alone left the user guessing what it counted. --}}
        <div class="input-group input-group-sm">
            <label class="visually-hidden" for="feeder-{{ $index }}-exp">{{ __('admin.recruitment_rules.partials.feeder_row.minimum_experience_value') }}</label>
            <input type="number" min="0" max="720"
                   class="form-control @error("feeders.{$index}.min_experience_years") is-invalid @enderror"
                   id="feeder-{{ $index }}-exp"
                   name="feeders[{{ $index }}][min_experience_years]"
                   value="{{ $row['min_experience_years'] ?? 0 }}" required
                   style="max-width:4.5rem">
            <label class="visually-hidden" for="feeder-{{ $index }}-unit">{{ __('admin.recruitment_rules.partials.feeder_row.minimum_experience_unit') }}</label>
            <select class="form-select @error("feeders.{$index}.min_experience_unit") is-invalid @enderror"
                    id="feeder-{{ $index }}-unit"
                    name="feeders[{{ $index }}][min_experience_unit]" required>
                @foreach ($units as $unit)
                    <option value="{{ $unit->value }}"
                        @selected(($row['min_experience_unit'] ?? 'years') === $unit->value)>
                        {{ $unit->label() }}
                    </option>
                @endforeach
            </select>
        </div>
        @error("feeders.{$index}.min_experience_years")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
        @error("feeders.{$index}.min_experience_unit")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    {{-- Item 11 (2026-08-26): several acceptable qualifications, not one.

         Still a `multiple` select underneath — the name ends in `[]` so an empty
         selection posts nothing and the source is recorded as prescribing no
         qualification, which is a real and common answer — but presented as a
         dropdown of checkboxes with a filter box by
         public/js/multiselect-dropdown.js.

         It carried `data-searchable` until 2026-09-06. That script is the
         single-value enhancement and assigns `select.value`, which on a `multiple`
         select collapses the answer to one option; a feeder that accepts three
         qualifications could not be recorded through it. --}}
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-qual">{{ __('admin.recruitment_rules.partials.feeder_row.minimum_qualification') }}</label>
        @php $selectedQualifications = array_map('intval', (array) ($row['qualification_ids'] ?? [])); @endphp
        <select class="form-select form-select-sm @error("feeders.{$index}.qualification_ids") is-invalid @enderror"
                id="feeder-{{ $index }}-qual"
                name="feeders[{{ $index }}][qualification_ids][]"
                size="4"
                multiple
                data-multiselect
                data-multiselect-none="{{ __('ui.multiselect_none') }}"
                data-multiselect-count="{{ __('ui.multiselect_count') }}"
                data-multiselect-no-match="{{ __('ui.multiselect_no_match') }}"
                data-multiselect-clear="{{ __('ui.clear') }}"
                data-multiselect-done="{{ __('ui.multiselect_done') }}"
                data-multiselect-search="{{ __('admin.recruitment_rules.partials.feeder_row.search_qualifications') }}">
            @foreach ($qualifications as $qualification)
                <option value="{{ $qualification->id }}"
                    @selected(in_array($qualification->id, $selectedQualifications, true))>
                    {{ $qualification->name_en }}
                </option>
            @endforeach
        </select>
        <div class="form-text">{{ __('admin.recruitment_rules.partials.feeder_row.qualifications_hint') }}</div>
        @error("feeders.{$index}.qualification_ids")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
        @error("feeders.{$index}.qualification_ids.*")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm remove-feeder"
                aria-label="{{ __('admin.recruitment_rules.partials.feeder_row.remove_this_promotion_from_post') }}">{{ __('ui.remove') }}</button>
    </td>
</tr>
