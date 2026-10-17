@extends('layouts.admin')

@section('title', __('dpcrules.title'))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('dpcrules.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('dpcrules.intro') }}</p>
        </div>
        <form method="GET" action="{{ route('admin.dpc-rules.index') }}" class="d-flex align-items-end gap-2">
            <div>
                <label class="form-label small mb-0" for="year">{{ __('dpcrules.show_for_year') }}</label>
                <input type="number" min="2000" max="2100" class="form-control form-control-sm" id="year" name="year" value="{{ $year }}">
            </div>
            <button class="btn btn-outline-secondary btn-sm">{{ __('dpcrules.show') }}</button>
        </form>
    </div>

    @include('partials.alerts')

    {{-- CR marks and qualifying scores --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>{{ __('dpcrules.cr.heading', ['year' => $year]) }}</strong>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.dpc-rules.cr-scales.create', ['year' => $year]) }}">{{ __('dpcrules.cr.record_new') }}</a>
        </div>
        <div class="card-body">
            @include('admin.dpc-rules.source', ['source' => $cr['source'], 'label' => $cr['source']?->name, 'document' => $cr['source'] ? route('admin.dpc-rules.cr-scales.document', $cr['source']) : null])

            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="small fw-semibold mb-1">{{ __('dpcrules.cr.marks_heading') }}</div>
                    <table class="table table-sm small mb-0">
                        <thead><tr><th>{{ __('dpcrules.cr.grade') }}</th><th class="text-end">{{ __('dpcrules.cr.marks') }}</th></tr></thead>
                        <tbody>
                            @foreach ($grades as $grade)
                                <tr><td>{{ $grade }} — {{ __('dpcrules.grade_names.'.$loop->index) }}</td><td class="text-end">{{ $cr['grade_marks'][$grade] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="col-lg-7">
                    <div class="small fw-semibold mb-1">{{ __('dpcrules.cr.qualifying_heading') }}</div>
                    <table class="table table-sm small">
                        <thead><tr><th>{{ __('dpcrules.cr.class_pair') }}</th><th class="text-end">{{ __('dpcrules.cr.minimum') }}</th></tr></thead>
                        <tbody>
                            @foreach ($pairs as $pair)
                                <tr><td>{{ $pair === 'default' ? __('dpcrules.cr.other_pairs') : __('dpcrules.cr.pair_label', ['pair' => $pair]) }}</td><td class="text-end">{{ $cr['qualifying_scores'][$pair] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <dl class="row small mb-0">
                        <dt class="col-sm-7">{{ __('dpcrules.cr.scoring_years') }}</dt><dd class="col-sm-5">{{ $cr['scoring_years'] }}</dd>
                        <dt class="col-sm-7">{{ __('dpcrules.cr.tribal_area_bonus') }}</dt><dd class="col-sm-5">{{ $cr['tribal_area_bonus'] }}</dd>
                        <dt class="col-sm-7">{{ __('dpcrules.cr.disqualifying_grade') }}</dt><dd class="col-sm-5">{{ $cr['disqualifying_grade'] }}</dd>
                        @foreach ($cr['last_year_forbidden_grades'] as $pair => $grade)
                            <dt class="col-sm-7">{{ __('dpcrules.cr.last_year_forbidden_for', ['pair' => $pair]) }}</dt><dd class="col-sm-5">{{ $grade }}</dd>
                        @endforeach
                        <dt class="col-sm-7">{{ __('dpcrules.cr.super_seat_grade') }}</dt><dd class="col-sm-5">{{ $cr['super_seat_grade'] }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- PH reservation --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>{{ __('dpcrules.ph.heading', ['year' => $year]) }}</strong>
            <a class="btn btn-primary btn-sm" href="{{ route('admin.dpc-rules.ph-rules.create', ['year' => $year]) }}">{{ __('dpcrules.ph.record_new') }}</a>
        </div>
        <div class="card-body">
            @include('admin.dpc-rules.source', ['source' => $ph['source'], 'label' => null, 'document' => $ph['source'] ? route('admin.dpc-rules.ph-rules.document', $ph['source']) : null])
            <p class="mb-1">{{ __('dpcrules.ph.summary', ['percentage' => rtrim(rtrim($ph['percentage'], '0'), '.'), 'rounding' => __('dpcrules.ph.roundings.'.$ph['rounding'])]) }}</p>
            <p class="small text-muted mb-0">{{ __('dpcrules.ph.fixed_terms') }}</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body small">
            {{ __('dpcrules.committee_pointer') }}
            <a href="{{ route('admin.committee-configurations.index') }}">{{ __('admin.committee_rules.title') }}</a>
        </div>
    </div>

    {{-- History --}}
    <h2 class="h6 mt-4">{{ __('dpcrules.history') }}</h2>
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table table-sm small mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('dpcrules.kind') }}</th><th>{{ __('dpcrules.effective_from_year') }}</th><th>{{ __('dpcrules.what') }}</th>
                    <th>{{ __('dpcrules.order') }}</th><th>{{ __('dpcrules.by') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($crScales as $scale)
                        <tr>
                            <td>{{ __('dpcrules.cr.kind') }}</td>
                            <td>{{ $scale->effective_from_year }}</td>
                            <td>
                                <strong>{{ $scale->name }}</strong>
                                <div class="text-muted">
                                    @foreach ($scale->grade_marks as $grade => $marks){{ $grade }}={{ $marks }}@if (! $loop->last), @endif @endforeach
                                    · {{ __('dpcrules.cr.qualifying_short') }}:
                                    @foreach ($scale->qualifying_scores as $pair => $minimum){{ $pair === 'default' ? __('dpcrules.cr.other_pairs') : $pair }} {{ $minimum }}@if (! $loop->last), @endif @endforeach
                                </div>
                                @if ($scale->remarks)<div class="text-muted">{{ $scale->remarks }}</div>@endif
                            </td>
                            <td>{{ $scale->order_number }} · {{ $scale->order_date->format('d-m-Y') }} · <a href="{{ route('admin.dpc-rules.cr-scales.document', $scale) }}">{{ $scale->original_name }}</a></td>
                            <td>{{ $scale->recorder?->name ?? '—' }}<div class="text-muted">{{ $scale->recorded_at->format('d-m-Y H:i') }}</div></td>
                        </tr>
                    @endforeach
                    @foreach ($phRules as $rule)
                        <tr>
                            <td>{{ __('dpcrules.ph.kind') }}</td>
                            <td>{{ $rule->effective_from_year }}</td>
                            <td>
                                {{ __('dpcrules.ph.summary', ['percentage' => rtrim(rtrim((string) $rule->percentage, '0'), '.'), 'rounding' => __('dpcrules.ph.roundings.'.$rule->rounding)]) }}
                                @if ($rule->remarks)<div class="text-muted">{{ $rule->remarks }}</div>@endif
                            </td>
                            <td>{{ $rule->order_number }} · {{ $rule->order_date->format('d-m-Y') }} · <a href="{{ route('admin.dpc-rules.ph-rules.document', $rule) }}">{{ $rule->original_name }}</a></td>
                            <td>{{ $rule->recorder?->name ?? '—' }}<div class="text-muted">{{ $rule->recorded_at->format('d-m-Y H:i') }}</div></td>
                        </tr>
                    @endforeach
                    @if ($crScales->isEmpty() && $phRules->isEmpty())
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ __('dpcrules.no_versions') }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endsection
