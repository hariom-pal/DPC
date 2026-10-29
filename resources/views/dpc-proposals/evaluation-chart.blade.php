@extends('layouts.app')

@section('title', __('ui.evaluation_tabulation_chart'))

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

        /*
         | `tribal_area` rides on the CR entry, not on a fourth array.
         |
         | It is a column on `dpc_evaluation_cr_grades`, because item 18 attaches
         | the benefit to the CR year — so the same `$flag()` reader is used, but
         | against `$cr` rather than a per-year record of its own.
         */
        $tribal = static fn (?array $item): string => match (true) {
            $item === null,
            ! array_key_exists('tribal_area', $item),
            $item['tribal_area'] === null,
            $item['tribal_area'] === '' => '',
            default => filter_var($item['tribal_area'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
        };

        return array_map(static fn (int $year): array => [
            'year' => $year,
            'grade' => $cr[$year]['grade'] ?? '',
            'loyalty' => $flag($loyalty[$year] ?? null),
            'property' => $flag($property[$year] ?? null),
            'tribal_area' => $tribal($cr[$year] ?? null),
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
            <h1 class="h4 mb-1">{{ __('ui.evaluation_tabulation_chart') }}</h1>
            <p class="text-muted small mb-2">{{ __('dpc.evaluation_chart.subheading', ['from' => $proposal->promotionFromPost->name, 'to' => $proposal->promotionToPost->name]) }}</p>

            {{-- Expected against present. The officer set is not the department's
                 to change, so the screen reports the two counts rather than
                 offering an "add officer" button that could only be refused. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <div class="row g-3 text-center">
                        <div class="col-6">
                            <div class="text-muted small">{{ __('dpc.evaluation_chart.expected_officers') }}</div>
                            <div class="h5 mb-0">{{ $chart['expected'] }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">{{ __('dpc.evaluation_chart.imported_officers') }}</div>
                            <div class="h5 mb-0 {{ $chart['matches'] ? '' : 'text-danger fw-semibold' }}">{{ $chart['present'] }}</div>
                        </div>
                    </div>

                    @if (! $chart['has_candidates'])
                        <hr class="my-3">
                        <p class="mb-0 small">
{!! __('dpc.evaluation_chart.generated_from_seniority_list', [
                                'link' => '<a href="'.e(route('dpc-proposals.seniority-list', $proposal)).'">'.e(__('ui.seniority_list')).'</a>',
                            ]) !!}
                        </p>
                    @elseif (! $chart['matches'])
                        <hr class="my-3">
                        <p class="mb-0 small text-danger">
                            {{ __('dpc.evaluation_chart.the_evaluation_chart_must_contain_exactly_the_sa') }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="alert alert-info py-2 small mb-3">
{!! __('dpc.evaluation_chart.what_you_enter', ['years' => $maxCrYears]) !!}
            </div>

            @if ($chart['has_candidates'])
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <a class="btn btn-outline-primary btn-sm"
                       href="{{ route('dpc-proposals.evaluation-chart.template', $proposal) }}">
                        {{ __('dpc.evaluation_chart.download_pre_filled_excel_template') }}
                    </a>
                    <span class="text-muted small">
                        {{ __('dpc.evaluation_chart.every_officer_is_already_on_it_fill_in_loyalty_i') }}
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
                                $row->crGrades->map->only(['year', 'grade', 'tribal_area'])->all(),
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
                                            {{ __('ui.dob_value', ['date' => $row->dob->format('d/m/Y')]) }}
                                            @if ($row->qualification) · {{ $row->qualification }} @endif
                                            @if ($row->reservationCategory) · {{ $row->reservationCategory->code }} @endif
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        {{-- Missing mandatory records (change register of
                                             2026-08-27, items 14 to 19). A link out rather
                                             than fields here: the reason and the PDF are a
                                             justification for a year, and a file upload on
                                             this all-officers form would lose every
                                             officer's data on one bad file.

                                             Outside the form element it sits in, because a
                                             link that navigated away mid-edit would discard
                                             the chart — hence the warning on the title. --}}
                                        <a class="btn btn-outline-secondary btn-sm"
                                           href="{{ route('dpc-proposals.record-gaps.index', [$proposal, $row]) }}"
                                           title="{{ __('dpc.evaluation_chart.missing_records_hint') }}">
                                            {{ __('dpc.evaluation_chart.missing_records') }}
                                            @if ($row->recordGaps->isNotEmpty())
                                                <span class="badge text-bg-warning ms-1">{{ $row->recordGaps->count() }}</span>
                                            @endif
                                        </a>
                                        <span class="badge text-bg-light border">{{ __('dpc.evaluation_chart.from_the_seniority_list_2') }}</span>
                                    </div>
                                </div>

                                <dl class="row small text-muted mb-3">
                                    <dt class="col-sm-3 fw-normal">{{ __('ui.promotion_to_post') }}</dt>
                                    <dd class="col-sm-3">{{ $row->promotion_to_post ?: '—' }}</dd>
                                    <dt class="col-sm-3 fw-normal">{{ __('ui.promotion_from_post') }}</dt>
                                    <dd class="col-sm-3">{{ $row->promotion_from_post ?: '—' }}</dd>

                                    <dt class="col-sm-3 fw-normal">{{ __('dpc.evaluation_chart.joined_service') }}</dt>
                                    <dd class="col-sm-3">{{ $row->date_of_joining_service?->format('d/m/Y') ?: '—' }}</dd>
                                    <dt class="col-sm-3 fw-normal">{{ __('dpc.evaluation_chart.on_promotion_to_post') }}</dt>
                                    <dd class="col-sm-3">{{ $row->date_of_posting_on_promotion_to_post?->format('d/m/Y') ?: '—' }}</dd>

                                    <dt class="col-sm-3 fw-normal">{{ __('dpc.evaluation_chart.on_promotion_from_post') }}</dt>
                                    <dd class="col-sm-9">{{ $row->date_of_posting_on_promotion_from_post?->format('d/m/Y') ?: '—' }}</dd>
                                </dl>

                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label" for="tribal-{{ $index }}">{{ __('dpc.evaluation_chart.tribal_area_service_yrs') }}</label>
                                        <input type="number" min="0" max="100" id="tribal-{{ $index }}"
                                               class="form-control form-control-sm @error('rows.'.$index.'.tribal_area_service_years') is-invalid @enderror"
                                               name="rows[{{ $index }}][tribal_area_service_years]" value="{{ $tribal }}">
                                        @error('rows.'.$index.'.tribal_area_service_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="note-{{ $index }}">{{ __('ui.note') }}</label>
                                        <input type="text" list="note-codes" id="note-{{ $index }}"
                                               class="form-control form-control-sm @error('rows.'.$index.'.note') is-invalid @enderror"
                                               name="rows[{{ $index }}][note]" value="{{ $note }}" placeholder="{{ __('dpc.evaluation_chart.e_g_punishment') }}">
                                        @error('rows.'.$index.'.note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h3 class="h6 mb-0">{{ __('dpc.evaluation_chart.year_wise_cr_loyalty_amp_immovable_property') }}</h3>
                                        <button type="button" class="btn btn-outline-secondary btn-sm add-year">{{ __('dpc.evaluation_chart.add_year') }}</button>
                                    </div>
                                    @error('rows.'.$index.'.cr_grades')<div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>@enderror
                                    <div class="table-responsive mt-1">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th style="width:16%">{{ __('ui.year') }}</th><th style="width:20%">{{ __('dpc.evaluation_chart.cr_grade') }}</th><th style="width:18%">{{ __('ui.loyalty') }}</th><th style="width:20%">{{ __('ui.immovable_property') }}</th><th style="width:18%">{{ __('dpc.evaluation_chart.tribal_area_year') }}</th><th></th></tr></thead>
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
                                                                <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                                                                @foreach ($crGrades as $grade)
                                                                    <option value="{{ $grade }}" @selected((string) $year['grade'] === (string) $grade)>{{ $grade }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][loyalty_flags][{{ $y }}][flag]">
                                                                <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                                                                <option value="1" @selected($year['loyalty'] === '1')>Y</option>
                                                                <option value="0" @selected($year['loyalty'] === '0')>N</option>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][property_flags][{{ $y }}][flag]">
                                                                <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                                                                <option value="1" @selected($year['property'] === '1')>Y</option>
                                                                <option value="0" @selected($year['property'] === '0')>N</option>
                                                            </select>
                                                        </td>
                                                        {{-- Tribal-area service in *this* year (item 18 of the
                                                             change register of 2026-08-27). Three states, not
                                                             two: "not recorded" is a different fact from
                                                             "recorded as no", and a chart filled in before this
                                                             register has neither. --}}
                                                        <td>
                                                            <select class="form-select form-select-sm" name="rows[{{ $index }}][cr_grades][{{ $y }}][tribal_area]">
                                                                <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                                                                <option value="1" @selected($year['tribal_area'] === '1')>Y</option>
                                                                <option value="0" @selected($year['tribal_area'] === '0')>N</option>
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
                        <button type="submit" class="btn btn-success">{{ __('dpc.evaluation_chart.save_evaluation_chart') }}</button>
                        <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
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
                    <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                    @foreach ($crGrades as $grade)<option value="{{ $grade }}">{{ $grade }}</option>@endforeach
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][loyalty_flags][__Y__][flag]">
                    <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                    <option value="1">Y</option>
                    <option value="0">N</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][property_flags][__Y__][flag]">
                    <option value="">{{ __('dpc.evaluation_chart.not_recorded_2') }}</option>
                    <option value="1">Y</option>
                    <option value="0">N</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" name="rows[__R__][cr_grades][__Y__][tribal_area]">
                    <option value=""></option>
                    <option value="1">Y</option>
                    <option value="0">N</option>
                </select>
            </td>
            <td><button type="button" class="btn btn-outline-danger btn-sm remove-year">×</button></td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script src="@versioned('js/forms/dpc-evaluation-chart.js')" defer></script>
@endpush
