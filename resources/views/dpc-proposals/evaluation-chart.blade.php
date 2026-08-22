@extends('layouts.app')

@section('title', 'Evaluation / Tabulation Chart')

@php
    /*
     * **Officer cards are rendered server-side now, not cloned from a template**
     * (business directive, 2026-08-22). The officer set is fixed by the approved
     * Seniority List, so there is nothing for a client-side repeater to add or
     * remove — and rendering identity as *text* rather than as disabled inputs
     * means there is no identity field on the page to re-enable.
     *
     * Only the per-year grid is still dynamic, because how many CR years an
     * officer has is genuinely variable.
     *
     * Loyalty, Property and CR are stored in three per-year tables (Q4) but S18
     * presents them as one grid, so they are merged for display and split again
     * on submit. Both sources are normalised to the same shape — flags as '', '1'
     * or '0' — because the DB gives booleans while a failed submit repopulates
     * from old(), which returns the raw three-array payload as strings.
     */
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

    // What the officer had typed before a failed submit, keyed by S.N. so a
    // redisplay does not lose it. Keyed on S.N. rather than the row index,
    // because the index is a form artefact and the S.N. is the officer.
    $submittedBySn = collect(old('rows', []))
        ->keyBy(fn ($row) => (int) ($row['sn'] ?? 0))
        ->map(fn ($row) => [
            'years' => $mergeYears($row['cr_grades'] ?? [], $row['loyalty_flags'] ?? [], $row['property_flags'] ?? []),
            'tribal_area_service_years' => $row['tribal_area_service_years'] ?? null,
            'note' => $row['note'] ?? null,
        ]);
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">Evaluation / Tabulation Chart</h1>
            <p class="text-muted small mb-2">मूल्यांकन पत्रक — {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}. A snapshot as of today; it will not change later even if HR records change.</p>

            {{-- Expected against present. The officer set is not the department's
                 to change, so the screen reports the two counts rather than
                 offering an "add officer" button that could only be refused. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <div class="row g-3 text-center">
                        <div class="col-6">
                            <div class="text-muted small">Expected Officers <span class="badge text-bg-secondary">from the Seniority List</span></div>
                            <div class="h5 mb-0">{{ $chart['expected'] }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Imported Officers</div>
                            <div class="h5 mb-0 {{ $chart['matches'] ? '' : 'text-danger fw-semibold' }}">{{ $chart['present'] }}</div>
                        </div>
                    </div>

                    @if (! $chart['has_candidates'])
                        <hr class="my-3">
                        <p class="mb-0 small">
                            <strong>Upload the Seniority List first.</strong> This chart is generated from it — the
                            officers, and everything the portal already knows about them, come from
                            <a href="{{ route('dpc-proposals.seniority-list', $proposal) }}">Seniority List</a>.
                        </p>
                    @elseif (! $chart['matches'])
                        <hr class="my-3">
                        <p class="mb-0 small text-danger">
                            The Evaluation Chart must contain exactly the same officers as the approved Seniority List.
                        </p>
                    @endif
                </div>
            </div>

            <div class="alert alert-info py-2 small mb-3">
                <strong>You enter evaluation data only.</strong> Employee number, name, date of birth,
                qualification, posts, category and every date come from the approved Seniority List and cannot be
                changed here — correct them on that screen and this chart follows.
                <br>
                Record up to {{ $maxCrYears }} CR years per officer, and <strong>leave a year blank where nothing
                was recorded</strong>: blank means <em>not recorded</em>, which is a different fact from "no" and is
                why a Circulation DPC exists. <strong>Mark</strong> and <strong>Remark</strong> are never entered
                here — the Commission's engine computes them at CALCULATE time.
            </div>

            @if ($chart['has_candidates'])
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <a class="btn btn-outline-primary btn-sm"
                       href="{{ route('dpc-proposals.evaluation-chart.template', $proposal) }}">
                        Download pre-filled Excel template
                    </a>
                    <span class="text-muted small">
                        Every officer is already on it. Fill in Loyalty, Immovable Property, CR, Tribal Area
                        Service and Note, then upload it back below.
                    </span>
                </div>

                @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('evaluation_chart_us')])
            @endif

            <form method="POST" action="{{ route('dpc-proposals.evaluation-chart.update', $proposal) }}">
                @csrf
                @method('PUT')

                @error('rows')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                <div id="rows">
                    @foreach ($proposal->evaluationRows->sortBy('sn')->values() as $index => $row)
                        @php
                            $submitted = $submittedBySn->get((int) $row->sn);
                            $years = $submitted['years'] ?? $mergeYears(
                                $row->crGrades->map->only(['year', 'grade'])->all(),
                                $row->loyaltyFlags->map->only(['year', 'flag'])->all(),
                                $row->propertyFlags->map->only(['year', 'flag'])->all(),
                            );
                            $tribal = $submitted['tribal_area_service_years'] ?? $row->tribal_area_service_years;
                            $note = $submitted['note'] ?? $row->note;
                        @endphp

                        <div class="card border-0 shadow-sm mb-3 eval-row-card"
                             data-row-index="{{ $index }}" data-year-count="{{ count($years) }}">
                            <div class="card-body">
                                {{-- The only identity field posted. The server already
                                     holds the rest; sending it would be offering a
                                     value that is going to be ignored. --}}
                                <input type="hidden" name="rows[{{ $index }}][sn]" value="{{ $row->sn }}">

                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <h2 class="h6 mb-0">
                                            <span class="badge text-bg-secondary">S.N. {{ $row->sn }}</span>
                                            {{ $row->name }}
                                            @if ($row->employee_number)
                                                <span class="text-muted fw-normal">({{ $row->employee_number }})</span>
                                            @endif
                                        </h2>
                                        <div class="text-muted small">
                                            DOB {{ $row->dob->format('d/m/Y') }}
                                            @if ($row->qualification) · {{ $row->qualification }} @endif
                                            @if ($row->reservationCategory) · {{ $row->reservationCategory->code }} @endif
                                        </div>
                                    </div>
                                    <span class="badge text-bg-light border">From the Seniority List</span>
                                </div>

                                <dl class="row small text-muted mb-3">
                                    <dt class="col-sm-3 fw-normal">Current Post</dt>
                                    <dd class="col-sm-3">{{ $row->current_post ?: '—' }}</dd>
                                    <dt class="col-sm-3 fw-normal">Feeder Post</dt>
                                    <dd class="col-sm-3">{{ $row->feeder_post ?: '—' }}</dd>

                                    <dt class="col-sm-3 fw-normal">Joined Service</dt>
                                    <dd class="col-sm-3">{{ $row->date_of_joining_service?->format('d/m/Y') ?: '—' }}</dd>
                                    <dt class="col-sm-3 fw-normal">On Current Post</dt>
                                    <dd class="col-sm-3">{{ $row->date_of_posting_on_current_post?->format('d/m/Y') ?: '—' }}</dd>

                                    <dt class="col-sm-3 fw-normal">On Feeder Post</dt>
                                    <dd class="col-sm-9">{{ $row->date_of_posting_on_feeder_post?->format('d/m/Y') ?: '—' }}</dd>
                                </dl>

                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label" for="tribal-{{ $index }}">Tribal-area Service (yrs)</label>
                                        <input type="number" min="0" max="100" id="tribal-{{ $index }}"
                                               class="form-control form-control-sm @error('rows.'.$index.'.tribal_area_service_years') is-invalid @enderror"
                                               name="rows[{{ $index }}][tribal_area_service_years]" value="{{ $tribal }}">
                                        @error('rows.'.$index.'.tribal_area_service_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="note-{{ $index }}">Note</label>
                                        <input type="text" list="note-codes" id="note-{{ $index }}"
                                               class="form-control form-control-sm @error('rows.'.$index.'.note') is-invalid @enderror"
                                               name="rows[{{ $index }}][note]" value="{{ $note }}" placeholder="e.g. PUNISHMENT">
                                        @error('rows.'.$index.'.note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h3 class="h6 mb-0">Year-wise CR, Loyalty &amp; Immovable Property</h3>
                                        <button type="button" class="btn btn-outline-secondary btn-sm add-year">+ Add Year</button>
                                    </div>
                                    @error('rows.'.$index.'.cr_grades')<div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>@enderror
                                    <div class="table-responsive mt-1">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th style="width:20%">Year</th><th style="width:25%">CR Grade</th><th style="width:22%">Loyalty</th><th style="width:25%">Immovable Property</th><th></th></tr></thead>
                                            <tbody class="year-rows">
                                                @foreach ($years as $y => $year)
                                                    <tr class="year-row">
                                                        <td>
                                                            <input type="number" class="form-control form-control-sm year-input"
                                                                   name="rows[{{ $index }}][cr_grades][{{ $y }}][year]" value="{{ $year['year'] }}" required>
                                                            <input type="hidden" class="year-mirror" name="rows[{{ $index }}][loyalty_flags][{{ $y }}][year]" value="{{ $year['year'] }}">
                                                            <input type="hidden" class="year-mirror" name="rows[{{ $index }}][property_flags][{{ $y }}][year]" value="{{ $year['year'] }}">
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][cr_grades][{{ $y }}][grade]">
                                                                <option value="">— not recorded —</option>
                                                                @foreach ($crGrades as $grade)
                                                                    <option value="{{ $grade }}" @selected((string) $year['grade'] === (string) $grade)>{{ $grade }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][loyalty_flags][{{ $y }}][flag]">
                                                                <option value="">— not recorded —</option>
                                                                <option value="1" @selected($year['loyalty'] === '1')>Y</option>
                                                                <option value="0" @selected($year['loyalty'] === '0')>N</option>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][property_flags][{{ $y }}][flag]">
                                                                <option value="">— not recorded —</option>
                                                                <option value="1" @selected($year['property'] === '1')>Y</option>
                                                                <option value="0" @selected($year['property'] === '0')>N</option>
                                                            </select>
                                                        </td>
                                                        <td><button type="button" class="btn btn-outline-danger btn-sm remove-year">×</button></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($chart['has_candidates'])
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success">Save Evaluation Chart</button>
                        <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <datalist id="note-codes">
        @foreach ($noteCodes as $code)<option value="{{ $code }}"></option>@endforeach
    </datalist>

    {{-- Only the year row is still cloned. There is no officer template, because
         there is no officer the department may add. --}}
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
    <script src="{{ asset('js/forms/dpc-evaluation-chart.js') }}" defer></script>
@endpush
