@extends('layouts.app')

@section('title', 'Evaluation / Tabulation Chart')

@php
    // Loyalty, Property and CR are stored in three per-year tables (Q4) but the
    // S18 chart presents them as one grid of year columns, so they are merged
    // back into a single per-year list for display and split again on submit.
    // Both sources are normalised to the same shape here — including flags as
    // '', '1' or '0' strings — so the repeater script has one case to handle:
    // the DB gives booleans, while a failed submit repopulates from old(),
    // which returns the raw three-array payload with string values.
    $mergeYears = static function (array $crGrades, array $loyaltyFlags, array $propertyFlags): array {
        $byYear = static fn (array $items): array => collect($items)
            ->filter(fn ($item) => ($item['year'] ?? null) !== null && $item['year'] !== '')
            ->keyBy(fn ($item) => (int) $item['year'])
            ->all();

        $cr = $byYear($crGrades);
        $loyalty = $byYear($loyaltyFlags);
        $property = $byYear($propertyFlags);

        $flag = static fn (?array $item): string => match (true) {
            $item === null, ! array_key_exists('flag', $item), $item['flag'] === null, $item['flag'] === '' => '',
            default => filter_var($item['flag'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        };

        $years = array_unique(array_merge(array_keys($cr), array_keys($loyalty), array_keys($property)));
        sort($years);

        return array_map(static fn (int $year): array => [
            'year' => $year,
            'grade' => $cr[$year]['grade'] ?? '',
            'loyalty' => $flag($loyalty[$year] ?? null),
            'property' => $flag($property[$year] ?? null),
        ], $years);
    };

    $rowsData = collect(old('rows'))->map(fn ($row) => array_merge($row, [
        'years' => $mergeYears($row['cr_grades'] ?? [], $row['loyalty_flags'] ?? [], $row['property_flags'] ?? []),
    ]))->all();

    if (old('rows') === null) {
        $rowsData = $proposal->evaluationRows->map(fn ($r) => [
            'sn' => $r->sn,
            'employee_number' => $r->employee_number,
            'name' => $r->name,
            'dob' => $r->dob?->format('Y-m-d'),
            'qualification' => $r->qualification,
            'current_post' => $r->current_post,
            'feeder_post' => $r->feeder_post,
            'reservation_category_id' => $r->reservation_category_id,
            'date_of_joining_service' => $r->date_of_joining_service?->format('Y-m-d'),
            'date_of_posting_on_current_post' => $r->date_of_posting_on_current_post?->format('Y-m-d'),
            'date_of_posting_on_feeder_post' => $r->date_of_posting_on_feeder_post?->format('Y-m-d'),
            'tribal_area_service_years' => $r->tribal_area_service_years,
            'note' => $r->note,
            'years' => $mergeYears(
                $r->crGrades->map->only(['year', 'grade'])->all(),
                $r->loyaltyFlags->map->only(['year', 'flag'])->all(),
                $r->propertyFlags->map->only(['year', 'flag'])->all(),
            ),
        ])->all();
    }
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">Evaluation / Tabulation Chart</h1>
            <p class="text-muted small mb-1">मूल्यांकन पत्रक — {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}. A snapshot as of today; it will not change later even if HR records change.</p>
            <div class="alert alert-info py-2 small">
                Enter recorded data only. <strong>Mark</strong> and <strong>Remark</strong> are not entered here — the Commission's engine computes them from this chart at CALCULATE time.
                Up to {{ $maxCrYears }} CR years may be recorded per officer; leave a year's value blank where nothing was recorded.
            </div>

            @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('evaluation_chart_us')])

            <form method="POST" action="{{ route('dpc-proposals.evaluation-chart.update', $proposal) }}">
                @csrf
                @method('PUT')

                @error('rows')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                <div id="rows"></div>

                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-row">+ Add Officer</button>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Save Evaluation Chart</button>
                    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <datalist id="note-codes">
        @foreach ($noteCodes as $code)<option value="{{ $code }}"></option>@endforeach
    </datalist>

    <template id="row-template">
        <div class="card border-0 shadow-sm mb-3 eval-row-card">
            <div class="card-body row g-2">
                <div class="col-md-1"><label class="form-label">S.N.</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="rows[__R__][sn]" required></div>
                <div class="col-md-2"><label class="form-label">Employee No.</label>
                    <input type="text" class="form-control form-control-sm" name="rows[__R__][employee_number]"></div>
                <div class="col-md-3"><label class="form-label">Name</label>
                    <input type="text" class="form-control form-control-sm" name="rows[__R__][name]" required></div>
                <div class="col-md-2"><label class="form-label">DOB</label>
                    <input type="date" class="form-control form-control-sm" name="rows[__R__][dob]" required></div>
                <div class="col-md-2"><label class="form-label">Qualification</label>
                    <input type="text" class="form-control form-control-sm" name="rows[__R__][qualification]"></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-eval-row">× Remove</button></div>

                <div class="col-md-3"><label class="form-label">Current Post</label>
                    <input type="text" class="form-control form-control-sm" name="rows[__R__][current_post]"></div>
                <div class="col-md-3"><label class="form-label">Feeder Post</label>
                    <input type="text" class="form-control form-control-sm" name="rows[__R__][feeder_post]"></div>
                <div class="col-md-2"><label class="form-label">Category</label>
                    <select class="form-select form-select-sm" name="rows[__R__][reservation_category_id]" required>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }}</option>@endforeach
                    </select></div>
                <div class="col-md-2"><label class="form-label">Tribal-area Service (yrs)</label>
                    <input type="number" min="0" max="100" class="form-control form-control-sm" name="rows[__R__][tribal_area_service_years]"></div>
                <div class="col-md-2"><label class="form-label">Note</label>
                    <input type="text" list="note-codes" class="form-control form-control-sm" name="rows[__R__][note]" placeholder="e.g. PUNISHMENT"></div>

                <div class="col-md-4"><label class="form-label">Date of Joining Service</label>
                    <input type="date" class="form-control form-control-sm" name="rows[__R__][date_of_joining_service]"></div>
                <div class="col-md-4"><label class="form-label">Date of Posting on Current Post</label>
                    <input type="date" class="form-control form-control-sm" name="rows[__R__][date_of_posting_on_current_post]"></div>
                <div class="col-md-4"><label class="form-label">Date of Posting on Feeder Post</label>
                    <input type="date" class="form-control form-control-sm" name="rows[__R__][date_of_posting_on_feeder_post]"></div>

                <div class="col-12 mt-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="h6 mb-0">Year-wise CR, Loyalty &amp; Immovable Property</h3>
                        <button type="button" class="btn btn-outline-secondary btn-sm add-year">+ Add Year</button>
                    </div>
                    <div class="table-responsive mt-1">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th style="width:20%">Year</th><th style="width:25%">CR Grade</th><th style="width:22%">Loyalty</th><th style="width:25%">Immovable Property</th><th></th></tr></thead>
                            <tbody class="year-rows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <template id="year-template">
        <tr class="year-row">
            <td>
                <input type="number" class="form-control form-control-sm year-input" name="rows[__R__][cr_grades][__Y__][year]" required>
                <input type="hidden" class="year-mirror" name="rows[__R__][loyalty_flags][__Y__][year]">
                <input type="hidden" class="year-mirror" name="rows[__R__][property_flags][__Y__][year]">
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][cr_grades][__Y__][grade]">
                    <option value="">— not recorded —</option>
                    @foreach ($crGrades as $grade)<option value="{{ $grade }}">{{ $grade }}</option>@endforeach
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][loyalty_flags][__Y__][flag]">
                    <option value="">— not recorded —</option>
                    <option value="1">Y</option>
                    <option value="0">N</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][property_flags][__Y__][flag]">
                    <option value="">— not recorded —</option>
                    <option value="1">Y</option>
                    <option value="0">N</option>
                </select>
            </td>
            <td><button type="button" class="btn btn-outline-danger btn-sm remove-year">×</button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
    (function () {
        const container = document.getElementById('rows');
        const rowTpl = document.getElementById('row-template').innerHTML;
        const yearTpl = document.getElementById('year-template').innerHTML;
        let rowIndex = 0;

        // The three per-year tables share one visible Year input; the two
        // hidden mirrors keep loyalty/property aligned to the same year.
        function syncYear(yearRow) {
            const value = yearRow.querySelector('.year-input').value;
            yearRow.querySelectorAll('.year-mirror').forEach(function (mirror) {
                mirror.value = value;
            });
        }

        function addYear(card, seed) {
            const body = card.querySelector('.year-rows');
            const idx = Number(card.dataset.yearCount || 0);
            card.dataset.yearCount = idx + 1;

            const html = yearTpl
                .replaceAll('__R__', card.dataset.rowIndex)
                .replaceAll('__Y__', idx);
            const tbody = document.createElement('tbody');
            tbody.innerHTML = html;
            const yearRow = tbody.firstElementChild;
            body.appendChild(yearRow);

            if (seed) {
                // Values arrive pre-normalised from the Blade block above.
                yearRow.querySelector('.year-input').value = seed.year ?? '';
                yearRow.querySelector('[name$="[grade]"]').value = seed.grade ?? '';
                yearRow.querySelector('[name$="[loyalty_flags][' + idx + '][flag]"]').value = seed.loyalty ?? '';
                yearRow.querySelector('[name$="[property_flags][' + idx + '][flag]"]').value = seed.property ?? '';
            }
            syncYear(yearRow);

            return yearRow;
        }

        function addRow(seed) {
            const idx = rowIndex++;
            const wrap = document.createElement('div');
            wrap.innerHTML = rowTpl.replaceAll('__R__', idx);
            const card = wrap.firstElementChild;
            card.dataset.rowIndex = idx;
            card.dataset.yearCount = 0;
            container.appendChild(card);

            if (seed) {
                ['sn', 'employee_number', 'name', 'dob', 'qualification', 'current_post', 'feeder_post',
                 'reservation_category_id', 'date_of_joining_service', 'date_of_posting_on_current_post',
                 'date_of_posting_on_feeder_post', 'tribal_area_service_years', 'note'].forEach(function (field) {
                    const input = card.querySelector('[name$="[' + field + ']"]');
                    if (input) input.value = seed[field] ?? '';
                });
                (seed.years || []).forEach(year => addYear(card, year));
            }

            if (Number(card.dataset.yearCount) === 0) addYear(card, null);

            return card;
        }

        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-eval-row')) e.target.closest('.eval-row-card').remove();
            if (e.target.classList.contains('remove-year')) e.target.closest('.year-row').remove();
            if (e.target.classList.contains('add-year')) addYear(e.target.closest('.eval-row-card'), null);
        });
        container.addEventListener('input', function (e) {
            if (e.target.classList.contains('year-input')) syncYear(e.target.closest('.year-row'));
        });
        document.getElementById('add-row').addEventListener('click', () => addRow(null));

        const seed = @json($rowsData);
        if (seed.length === 0) {
            addRow(null);
        } else {
            seed.forEach(row => addRow(row));
        }
    })();
</script>
@endpush
