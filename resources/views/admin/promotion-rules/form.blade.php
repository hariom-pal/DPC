@extends('layouts.admin')

@section('title', __('admin.promotion_rules.add'))

@section('content')
    @php
        $val = fn (string $key, $default = null) => old($key, $default);
        $row = fn (int $before) => $current?->years->firstWhere('years_before', $before);
        $count = fn (string $field, int $fallback) => (int) old($field, $current?->{$field} ?? $fallback);
    @endphp

    <a href="{{ route('admin.service-classes.promotion-rules.index', $class) }}" class="small">&larr; {{ __('admin.promotion_rules.title', ['class' => $class->name_en]) }}</a>
    <h1 class="h4 mb-1">{{ __('admin.promotion_rules.add') }} — {{ $class->name_en }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.promotion_rules.form_intro') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    @if ($grades === [])
        <div class="alert alert-warning">
            {{ __('admin.promotion_rules.errors.no_grades', ['year' => $year]) }}
            <a href="{{ route('admin.cr-grades.index') }}">{{ __('admin.cr_grades.title') }}</a>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.service-classes.promotion-rules.store', $class) }}" id="promotion-rule-form">
        @csrf

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>1. {{ __('admin.promotion_rules.step_which') }}</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="from_class">{{ __('admin.promotion_rules.from_class') }}</label>
                    <select class="form-select @error('from_class') is-invalid @enderror" id="from_class" name="from_class" required>
                        @foreach ($fromClasses as $option)
                            <option value="{{ $option->code }}" @selected($val('from_class', $from) === $option->code)>{{ __('admin.promotion_rules.pair', ['from' => $option->name_en, 'to' => $class->name_en]) }}</option>
                        @endforeach
                    </select>
                    @error('from_class')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="effective_from_year">{{ __('admin.promotion_rules.effective_from_year') }}</label>
                    <input type="number" min="2000" max="2100" class="form-control @error('effective_from_year') is-invalid @enderror" id="effective_from_year" name="effective_from_year" value="{{ $year }}" required>
                    @error('effective_from_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5"><div class="form-text mt-md-4">{{ __('admin.promotion_rules.effective_help') }}</div></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>2. {{ __('admin.promotion_rules.step_counts') }}</strong></div>
            <div class="card-body row g-3">
                @foreach (['cr_years' => 5, 'loyalty_years' => 5, 'property_years' => 5] as $field => $default)
                    <div class="col-md-3">
                        <label class="form-label" for="{{ $field }}">{{ __('admin.promotion_rules.'.$field) }}</label>
                        <input type="number" min="{{ $field === 'cr_years' ? 1 : 0 }}" max="{{ $maxYears }}" class="form-control @error($field) is-invalid @enderror js-year-count"
                               id="{{ $field }}" name="{{ $field }}" value="{{ $count($field, $default) }}" required @error($field) autofocus @enderror>
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="col-12"><div class="form-text">{{ __('admin.promotion_rules.counts_help', ['year' => $year, 'last' => $year - 1]) }}</div></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>3. {{ __('admin.promotion_rules.step_years') }}</strong></div>
            <div class="card-body">
                <p class="small text-muted">{{ __('admin.promotion_rules.years_help') }}
                    @if ($grades !== []) {{ __('admin.promotion_rules.grades_available', ['grades' => implode(', ', $grades)]) }} @endif</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr>
                            <th>{{ __('admin.promotion_rules.year') }}</th>
                            <th>{{ __('admin.promotion_rules.cr_min_marks') }}</th>
                            <th class="text-center">{{ __('admin.promotion_rules.cr_mandatory') }}</th>
                            <th class="text-center">{{ __('admin.promotion_rules.loyalty_mandatory') }}</th>
                            <th class="text-center">{{ __('admin.promotion_rules.property_mandatory') }}</th>
                        </tr></thead>
                        <tbody>
                            @for ($before = 1; $before <= $maxYears; $before++)
                                @php $existing = $row($before); @endphp
                                <tr class="js-year-row" data-before="{{ $before }}">
                                    <td class="small text-nowrap">
                                        <span class="js-year-label">{{ __('admin.promotion_rules.year_label', ['n' => $before, 'year' => $year - $before]) }}</span>
                                    </td>
                                    <td style="min-width: 9rem">
                                        <select class="form-select form-select-sm js-cr-field @error('years.'.$before.'.cr_min_marks') is-invalid @enderror" name="years[{{ $before }}][cr_min_marks]" aria-label="{{ __('admin.promotion_rules.cr_min_marks') }}">
                                            <option value="">—</option>
                                            @foreach ($grades as $grade)
                                                <option value="{{ $grade }}" @selected((string) $val('years.'.$before.'.cr_min_marks', $existing?->cr_min_marks) === (string) $grade)>{{ $grade }}</option>
                                            @endforeach
                                        </select>
                                        @error('years.'.$before.'.cr_min_marks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    @foreach (['cr_mandatory' => 'js-cr-field', 'loyalty_mandatory' => 'js-loyalty-field', 'property_mandatory' => 'js-property-field'] as $field => $class_)
                                        <td class="text-center">
                                            <input type="hidden" name="years[{{ $before }}][{{ $field }}]" value="0">
                                            <input class="form-check-input {{ $class_ }}" type="checkbox" value="1" name="years[{{ $before }}][{{ $field }}]"
                                                   aria-label="{{ __('admin.promotion_rules.'.$field) }}"
                                                   @checked((bool) $val('years.'.$before.'.'.$field, $existing?->{$field} ?? true))>
                                        </td>
                                    @endforeach
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <div class="form-text">{{ __('admin.promotion_rules.mandatory_help') }}</div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>4. {{ __('admin.promotion_rules.step_marks') }}</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="qualifying_total">{{ __('admin.promotion_rules.qualifying_total') }}</label>
                    <input type="number" step="1" min="1" class="form-control @error('qualifying_total') is-invalid @enderror" id="qualifying_total" name="qualifying_total"
                           value="{{ $val('qualifying_total', $current?->qualifying_total ?? '') }}" required>
                    @error('qualifying_total')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('admin.promotion_rules.qualifying_help') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="super_seat_grade">{{ __('admin.promotion_rules.super_seat_grade') }}</label>
                    <select class="form-select @error('super_seat_grade') is-invalid @enderror" id="super_seat_grade" name="super_seat_grade">
                        <option value="">{{ __('admin.promotion_rules.none') }}</option>
                        @foreach ($grades as $grade)
                            <option value="{{ $grade }}" @selected((string) $val('super_seat_grade', $current?->super_seat_grade) === (string) $grade)>{{ $grade }}</option>
                        @endforeach
                    </select>
                    @error('super_seat_grade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('admin.promotion_rules.super_seat_help') }}</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tribal_bonus">{{ __('admin.promotion_rules.tribal_bonus') }}</label>
                    <input type="number" min="0" max="10" class="form-control @error('tribal_bonus') is-invalid @enderror" id="tribal_bonus" name="tribal_bonus" value="{{ $val('tribal_bonus', $current?->tribal_bonus ?? 0) }}" required>
                    @error('tribal_bonus')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('admin.promotion_rules.tribal_help') }}</div>
                </div>
                <div class="col-md-6">
                    <input type="hidden" name="tribal_bonus_skips_extremes" value="0">
                    <div class="form-check mt-md-4">
                        <input class="form-check-input" type="checkbox" id="tribal_bonus_skips_extremes" name="tribal_bonus_skips_extremes" value="1"
                               @checked((bool) $val('tribal_bonus_skips_extremes', $current?->tribal_bonus_skips_extremes ?? true))>
                        <label class="form-check-label" for="tribal_bonus_skips_extremes">{{ __('admin.promotion_rules.tribal_skips_extremes') }}</label>
                    </div>
                    <div class="form-text">{{ __('admin.promotion_rules.tribal_skips_extremes_help') }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="remarks">{{ __('ui.remarks') }}</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" maxlength="2000">{{ $val('remarks') }}</textarea>
                </div>
            </div>
        </div>

        <button class="btn btn-success">{{ __('admin.promotion_rules.save') }}</button>
        <a href="{{ route('admin.service-classes.promotion-rules.index', $class) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
    </form>
@endsection

@push('scripts')
    <script src="@versioned('js/forms/promotion-rule.js')" defer></script>
@endpush
