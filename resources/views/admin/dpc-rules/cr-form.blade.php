@extends('layouts.admin')

@section('title', __('dpcrules.cr.record_new'))

@section('content')
    <h1 class="h4 mb-1">{{ __('dpcrules.cr.record_new') }}</h1>
    <p class="text-muted small mb-3">{{ __('dpcrules.cr.form_intro') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.dpc-rules.cr-scales.store') }}">
        @csrf
        @include('admin.dpc-rules.year-field')

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>2. {{ __('dpcrules.cr.marks_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">{{ __('dpcrules.cr.name') }}</label>
                        <input type="text" maxlength="150" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="{{ __('dpcrules.cr.name_placeholder') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    @foreach ($grades as $grade)
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="mark_{{ $loop->index }}">{{ $grade }} <span class="text-muted small">{{ __('dpcrules.grade_names.'.$loop->index) }}</span></label>
                            <input type="number" min="0" max="100" class="form-control @error('grade_marks.'.$grade) is-invalid @enderror" id="mark_{{ $loop->index }}" name="grade_marks[{{ $grade }}]"
                                   value="{{ old('grade_marks.'.$grade, $current['grade_marks'][$grade] ?? '') }}" required>
                            @error('grade_marks.'.$grade)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <div class="col-12"><div class="form-text">{{ __('dpcrules.cr.marks_help') }}</div></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>3. {{ __('dpcrules.cr.qualifying_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($pairs as $pair)
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="q_{{ $loop->index }}">{{ $pair === 'default' ? __('dpcrules.cr.other_pairs') : __('dpcrules.cr.pair_label', ['pair' => $pair]) }}</label>
                            <input type="number" min="0" class="form-control @error('qualifying_scores.'.$pair) is-invalid @enderror" id="q_{{ $loop->index }}" name="qualifying_scores[{{ $pair }}]"
                                   value="{{ old('qualifying_scores.'.$pair, $current['qualifying_scores'][$pair] ?? '') }}" required>
                            @error('qualifying_scores.'.$pair)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <div class="col-12"><div class="form-text">{{ __('dpcrules.cr.qualifying_help') }}</div></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>4. {{ __('dpcrules.cr.other_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="scoring_years">{{ __('dpcrules.cr.scoring_years') }}</label>
                        <input type="number" min="1" max="10" class="form-control @error('scoring_years') is-invalid @enderror" id="scoring_years" name="scoring_years" value="{{ old('scoring_years', $current['scoring_years']) }}" required>
                        @error('scoring_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tribal_area_bonus">{{ __('dpcrules.cr.tribal_area_bonus') }}</label>
                        <input type="number" min="0" max="10" class="form-control @error('tribal_area_bonus') is-invalid @enderror" id="tribal_area_bonus" name="tribal_area_bonus" value="{{ old('tribal_area_bonus', $current['tribal_area_bonus']) }}" required>
                        @error('tribal_area_bonus')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="disqualifying_grade">{{ __('dpcrules.cr.disqualifying_grade') }}</label>
                        <select class="form-select @error('disqualifying_grade') is-invalid @enderror" id="disqualifying_grade" name="disqualifying_grade" required>
                            @foreach ($grades as $grade)
                                <option value="{{ $grade }}" @selected(old('disqualifying_grade', $current['disqualifying_grade']) === $grade)>{{ $grade }}</option>
                            @endforeach
                        </select>
                        @error('disqualifying_grade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="super_seat_grade">{{ __('dpcrules.cr.super_seat_grade') }}</label>
                        <select class="form-select @error('super_seat_grade') is-invalid @enderror" id="super_seat_grade" name="super_seat_grade" required>
                            @foreach ($grades as $grade)
                                <option value="{{ $grade }}" @selected(old('super_seat_grade', $current['super_seat_grade']) === $grade)>{{ $grade }}</option>
                            @endforeach
                        </select>
                        @error('super_seat_grade')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @foreach ($gatedPairs as $pair)
                        <div class="col-md-4">
                            <label class="form-label" for="lyf_{{ $loop->index }}">{{ __('dpcrules.cr.last_year_forbidden_for', ['pair' => $pair]) }}</label>
                            <select class="form-select @error('last_year_forbidden_grades.'.$pair) is-invalid @enderror" id="lyf_{{ $loop->index }}" name="last_year_forbidden_grades[{{ $pair }}]">
                                <option value="">{{ __('dpcrules.cr.no_gate') }}</option>
                                @foreach ($grades as $grade)
                                    <option value="{{ $grade }}" @selected(old('last_year_forbidden_grades.'.$pair, $current['last_year_forbidden_grades'][$pair] ?? '') === $grade)>{{ $grade }}</option>
                                @endforeach
                            </select>
                            @error('last_year_forbidden_grades.'.$pair)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @include('admin.dpc-rules.document-fields', ['step' => 5])

        <button class="btn btn-primary">{{ __('dpcrules.save_version') }}</button>
        <a href="{{ route('admin.dpc-rules.index', ['year' => $year]) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
    </form>
@endsection
