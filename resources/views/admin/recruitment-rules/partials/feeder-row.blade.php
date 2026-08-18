{{--
    One row of the feeder grid.

    Shared by the saved rows and by the <template> the Add button clones, so the
    two can never drift apart — a blank row is this same markup with $row null and
    $index the literal string __INDEX__.

    $index          int|string  array index, or '__INDEX__' in the template
    $row            array|null  existing values, keyed as the request expects
    $cadres         Collection  every active cadre (filtered client-side by department)
    $qualifications Collection  Active qualifications only
    $units          array       ExperienceUnit cases
--}}
<tr class="feeder-row">
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-cadre">Feeder cadre</label>
        <select class="form-select form-select-sm cadre-select @error("feeders.{$index}.feeder_cadre_id") is-invalid @enderror"
                id="feeder-{{ $index }}-cadre"
                name="feeders[{{ $index }}][feeder_cadre_id]" required
                data-searchable data-searchable-placeholder="Search cadres…">
            <option value="">— Select —</option>
            @foreach ($cadres as $cadre)
                <option value="{{ $cadre->id }}"
                        data-department="{{ $cadre->department_id }}"
                        @selected(($row['feeder_cadre_id'] ?? null) == $cadre->id)>
                    {{ $cadre->name }} ({{ $cadre->class_level->label() }})
                </option>
            @endforeach
        </select>
        @error("feeders.{$index}.feeder_cadre_id")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-share">Promotion share percentage</label>
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
            <label class="visually-hidden" for="feeder-{{ $index }}-exp">Minimum experience value</label>
            <input type="number" min="0" max="720"
                   class="form-control @error("feeders.{$index}.min_experience_years") is-invalid @enderror"
                   id="feeder-{{ $index }}-exp"
                   name="feeders[{{ $index }}][min_experience_years]"
                   value="{{ $row['min_experience_years'] ?? 0 }}" required
                   style="max-width:4.5rem">
            <label class="visually-hidden" for="feeder-{{ $index }}-unit">Minimum experience unit</label>
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
    <td>
        <label class="visually-hidden" for="feeder-{{ $index }}-qual">Minimum qualification</label>
        <select class="form-select form-select-sm @error("feeders.{$index}.qualification_id") is-invalid @enderror"
                id="feeder-{{ $index }}-qual"
                name="feeders[{{ $index }}][qualification_id]"
                data-searchable data-searchable-placeholder="Search qualifications…">
            <option value="">— None specified —</option>
            @foreach ($qualifications as $qualification)
                <option value="{{ $qualification->id }}"
                    @selected(($row['qualification_id'] ?? null) == $qualification->id)>
                    {{ $qualification->name_en }}
                </option>
            @endforeach
        </select>
        @error("feeders.{$index}.qualification_id")
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm remove-feeder"
                aria-label="Remove this feeder cadre">Remove</button>
    </td>
</tr>
