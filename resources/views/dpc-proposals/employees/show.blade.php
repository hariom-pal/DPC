@extends('layouts.app')
@section('title', __('dpc.employee_details.show_title', ['name' => $row->name]))
@section('content')
    @php
        $docs = $row->currentDocuments;
        $docFor = fn (string $kind, ?int $year = null) => $docs->first(fn ($d) => $d->kind->value === $kind && $d->year === $year);
        $crYears = $row->crGrades->sortBy('year')->values();
        $propertyYears = $row->propertyFlags->sortBy('year')->values();
        $editCr = request()->integer('cr_year') ? $crYears->firstWhere('year', request()->integer('cr_year')) : null;
        $editProperty = request()->integer('property_year') ? $propertyYears->firstWhere('year', request()->integer('property_year')) : null;
        $crGapYears = $row->recordGaps->where('kind', 'cr')->filter(fn ($g) => $g->hasDocument())->pluck('year')->all();
        $outcome = $row->phOutcome();
        $grader = app(\App\Domain\Dpc\Services\EmployeeDetailsService::class);
        $sectionLabels = [
            'basic' => __('dpc.employee_details.sections.basic'),
            'cr' => __('dpc.employee_details.sections.cr'),
            'property' => __('dpc.employee_details.sections.property'),
            'ph' => __('dpc.employee_details.sections.ph'),
        ];
    @endphp

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a>
            </li>
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.employees.index', $proposal) }}">{{ __('dpc.employee_details.index_title') }}</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">{{ $row->name }}</li>
        </ol>
    </nav>

    @include('partials.alerts')

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $row->name }}</h1>
            <p class="text-muted mb-0">{{ __('dpc.employee_details.show_lead', ['sn' => $row->sn, 'employee' => $row->employee_number ?? '—']) }}</p>
        </div>
        <div class="d-flex gap-2">
            @if ($previousRow)
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('dpc-proposals.employees.show', [$proposal, $previousRow]) }}">← {{ __('dpc.employee_details.previous') }}</a>
            @endif
            @if ($nextRow)
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('dpc-proposals.employees.show', [$proposal, $nextRow]) }}">{{ __('dpc.employee_details.next') }} →</a>
            @endif
            @if ($nextPending)
                <a class="btn btn-primary btn-sm" href="{{ route('dpc-proposals.employees.show', [$proposal, $nextPending]) }}">{{ __('dpc.employee_details.next_pending') }}</a>
            @endif
        </div>
    </div>

    @unless ($canEdit)
        <div class="alert alert-secondary py-2 small">{{ __('dpc.employee_details.read_only') }}</div>
    @endunless

    {{-- G. Completion status — first, so the officer sees what is left before scrolling. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-status">
        <div class="card-body">
            <h2 class="h6">G. {{ __('dpc.employee_details.sections.status') }}</h2>
            @if ($status['complete'])
                <span class="badge text-bg-success" id="employee-status">{{ __('dpc.employee_details.status.complete') }}</span>
            @else
                <span class="badge text-bg-warning" id="employee-status">{{ $status['summary'] }}</span>
                <ul class="small mt-2 mb-0">
                    @foreach ($status['sections'] as $key => $missing)
                        @foreach ($missing as $item)
                            <li><strong>{{ $sectionLabels[$key] }}:</strong> {{ $item }}</li>
                        @endforeach
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- A. Basic information --}}
    <div class="card border-0 shadow-sm mb-3" id="section-basic">
        <div class="card-body">
            <h2 class="h6">A. {{ __('dpc.employee_details.sections.basic') }}</h2>
            @error('basic')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            <dl class="row small mb-2">
                <dt class="col-sm-3">{{ __('dpc.employee_details.employee_id') }}</dt><dd class="col-sm-9">{{ $row->employee_number ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('ui.dob') }}</dt><dd class="col-sm-9">{{ $row->dob?->format('d-m-Y') }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.date_of_appointment') }}</dt><dd class="col-sm-9">{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</dd>
            </dl>
            <form method="POST" action="{{ route('dpc-proposals.employees.basic', [$proposal, $row]) }}" class="row g-2">
                @csrf @method('PUT')
                @if (config('dpc.reservation.category_wise'))
                <div class="col-md-3">
                    <label class="form-label small" for="reservation_category_id">{{ __('ui.reservation_category') }}</label>
                    <select class="form-select form-select-sm" id="reservation_category_id" name="reservation_category_id" @disabled(! $canEdit)>
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) old('reservation_category_id', $row->reservation_category_id) === $category->id)>{{ $category->code }} — {{ $category->name_en }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">{{ __('dpc.employee_details.category_help') }}</div>
                </div>
                @endif
                <div class="col-md-3">
                    <label class="form-label small" for="qualification">{{ __('ui.qualification') }}</label>
                    <input class="form-control form-control-sm" id="qualification" name="qualification" value="{{ old('qualification', $row->qualification) }}" @disabled(! $canEdit)>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="mobile">{{ __('dpc.employee_details.mobile') }}</label>
                    <input class="form-control form-control-sm" id="mobile" name="mobile" value="{{ old('mobile', $row->mobile) }}" @disabled(! $canEdit)>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="date_of_posting_on_promotion_from_post">{{ __('dpc.employee_details.posting_on_current_post') }}</label>
                    <input type="date" class="form-control form-control-sm" id="date_of_posting_on_promotion_from_post" name="date_of_posting_on_promotion_from_post"
                           value="{{ old('date_of_posting_on_promotion_from_post', $row->date_of_posting_on_promotion_from_post?->format('Y-m-d')) }}" @disabled(! $canEdit)>
                </div>
                @if ($canEdit)
                    <div class="col-12"><button class="btn btn-success btn-sm">{{ __('dpc.employee_details.save_section') }}</button></div>
                @endif
            </form>
        </div>
    </div>

    {{-- B. Seniority information — the list's, not editable here. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-seniority">
        <div class="card-body">
            <h2 class="h6">B. {{ __('dpc.employee_details.sections.seniority') }}</h2>
            <dl class="row small mb-0">
                <dt class="col-sm-3">{{ __('dpc.employee_details.seniority_no') }}</dt><dd class="col-sm-9">{{ $row->sn }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.current_post') }}</dt><dd class="col-sm-9">{{ $row->promotion_from_post ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('dpc.seniority_list.as_on_label') }}</dt>
                <dd class="col-sm-9">{{ $proposal->seniority_list_as_on?->format('d-m-Y') ?? '—' }} ({{ __('dpc.seniority_list.as_on_required', ['date' => $proposal->seniorityReferenceDate()->format('d-m-Y')]) }})</dd>
            </dl>
            <p class="small text-muted mb-0">{{ __('dpc.employee_details.seniority_note') }}</p>
        </div>
    </div>

    {{-- C. Confidential Reports --}}
    <div class="card border-0 shadow-sm mb-3" id="section-cr">
        <div class="card-body">
            <h2 class="h6">C. {{ __('dpc.employee_details.sections.cr') }}</h2>
            <p class="small text-muted">{{ __('dpc.employee_details.cr.lead', ['count' => $requiredCrCount]) }}</p>
            @error('cr')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            {{-- The candidate against the Class Master promotion rule (points: Eligible CR Grades). --}}
            <div class="border rounded p-2 mb-3" id="promotion-rule-check">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
                    <strong class="small">{{ __('dpc.employee_details.rule.heading') }}</strong>
                    <span class="badge {{ ['meets' => 'text-bg-success', 'does_not_meet' => 'text-bg-danger', 'no_rule' => 'text-bg-secondary'][$ruleCheck['status']] }}" data-rule-status="{{ $ruleCheck['status'] }}">{{ __('dpc.employee_details.rule.status.'.$ruleCheck['status']) }}</span>
                </div>
                @if ($ruleCheck['rule'] !== null)
                    <p class="small text-muted mb-2">{{ __('dpc.employee_details.rule.summary', [
                        'from' => $ruleCheck['rule']->from_class,
                        'to' => $ruleCheck['rule']->to_class,
                        'since' => $ruleCheck['rule']->effective_from_year,
                        'cr' => $ruleCheck['rule']->cr_years,
                        'average' => number_format((float) $ruleCheck['rule']->qualifying_average, 2),
                    ]) }}</p>
                    <div class="table-responsive">
                        <table class="table table-sm mb-2 small align-middle">
                            <thead><tr>
                                <th>{{ __('ui.year') }}</th>
                                <th>{{ __('dpc.employee_details.rule.points') }}</th>
                                <th>{{ __('dpc.employee_details.rule.min') }}</th>
                                <th>{{ __('dpc.employee_details.sections.cr') }}</th>
                                <th>{{ __('dpc.employee_details.sections.property') }}</th>
                                <th>{{ __('dpc.employee_details.rule.loyalty') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($ruleCheck['years'] as $line)
                                    <tr data-rule-year="{{ $line['year'] }}">
                                        <td>{{ $line['year'] }}</td>
                                        <td>{{ $line['points'] ?? '—' }}</td>
                                        <td>{{ $line['min'] ?? '—' }}</td>
                                        <td>{!! $line['cr_ok'] ? ($line['cr_required'] ? '✓' : '—') : '<span class="text-danger">✗</span>' !!}</td>
                                        <td>{!! $line['property_ok'] ? ($line['property_required'] ? '✓' : '—') : '<span class="text-danger">✗</span>' !!}</td>
                                        <td>{!! $line['loyalty_ok'] ? ($line['loyalty_required'] ? '✓' : '—') : '<span class="text-danger">✗</span>' !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @php
                        $ruleYears = app(\App\Domain\Dpc\Services\EmployeeDetailsService::class)->ruleCrYears($row);
                    @endphp
                    @if ($ruleYears !== [])
                        <p class="small mb-1" id="rule-required-years">{{ __('dpc.employee_details.rule.required_years', ['years' => implode(', ', $ruleYears)]) }}</p>
                    @endif
                    <p class="small mb-1">{{ __('dpc.employee_details.rule.average', ['average' => $ruleCheck['average'] === null ? '—' : number_format($ruleCheck['average'], 2)]) }}</p>
                @endif
                @if ($ruleCheck['findings'] !== [])
                    <ul class="small text-danger mb-0">
                        @foreach ($ruleCheck['findings'] as $finding)<li>{{ $finding }}</li>@endforeach
                    </ul>
                @endif
                <p class="small text-muted mb-0 mt-1">{{ __('dpc.employee_details.rule.note') }}</p>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('ui.year') }}</th>
                        <th>{{ __('dpc.employee_details.cr.put_up') }}</th>
                        <th>{{ __('dpc.employee_details.cr.grading') }}</th>
                        <th>{{ __('dpc.employee_details.cr.reports') }}</th>
                        <th>{{ __('dpc.employee_details.cr.year_grade') }}</th>
                        <th>{{ __('dpc.employee_details.documents.heading') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($crYears as $cr)
                            <tr data-cr-year="{{ $cr->year }}">
                                <td>{{ $cr->year }}</td>
                                <td>{{ $cr->put_up === null ? __('dpc.employee_details.not_answered') : ($cr->put_up ? __('ui.yes') : __('ui.no')) }}</td>
                                <td>{{ $cr->has_grading === null ? '—' : ($cr->has_grading ? __('ui.yes') : __('ui.no')) }}</td>
                                <td class="small">
                                    @foreach ((array) $cr->reports as $i => $report)
                                        <div>#{{ $i + 1 }}: {{ $grader->finalGrade($report) }}
                                            @if ($grader->reportNeedsNrc($report))<span class="badge text-bg-light">{{ __('dpc.employee_details.cr.nrc_needed') }}</span>@endif
                                        </div>
                                    @endforeach
                                </td>
                                <td><strong>{{ $cr->grade ?? '—' }}</strong></td>
                                <td class="small">
                                    @foreach (['cr_report', 'cr_without_grading', 'cr_nrc'] as $kind)
                                        @if ($document = $docFor($kind, $cr->year))
                                            <div><a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $document]) }}">{{ $document->kind->label() }}</a></div>
                                        @endif
                                    @endforeach
                                    @if ($cr->put_up === false)
                                        <div>
                                            @if (in_array($cr->year, $crGapYears, true))
                                                <span class="badge text-bg-success">{{ __('dpc.employee_details.cr.reason_recorded') }}</span>
                                            @endif
                                            <a href="{{ route('dpc-proposals.record-gaps.index', [$proposal, $row]) }}">{{ __('dpc.employee_details.cr.record_reason') }}</a>
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEdit)
                                        <a class="btn btn-outline-primary btn-sm" href="{{ route('dpc-proposals.employees.show', [$proposal, $row, 'cr_year' => $cr->year]) }}#section-cr">{{ __('ui.edit') }}</a>
                                        <form method="POST" class="d-inline" action="{{ route('dpc-proposals.employees.cr.remove', [$proposal, $row, $cr->year]) }}"
                                              data-confirm="{{ __('dpc.employee_details.remove_year_confirm', ['year' => $cr->year]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">{{ __('dpc.employee_details.remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center">{{ __('dpc.employee_details.cr.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <form method="POST" action="{{ route('dpc-proposals.employees.cr.save', [$proposal, $row]) }}" class="border rounded p-2 mb-3" data-employee-cr-form>
                    @csrf
                    <h3 class="h6">{{ $editCr ? __('dpc.employee_details.cr.edit_year', ['year' => $editCr->year]) : __('dpc.employee_details.cr.add_year') }}</h3>
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small" for="cr-year">{{ __('ui.year') }}</label>
                            <input type="number" class="form-control form-control-sm" id="cr-year" name="year" required min="{{ config('dpc.evaluation.min_year') }}" max="{{ $latestYear }}"
                                   value="{{ old('year', $editCr?->year) }}" @if ($editCr) readonly @endif>
                        </div>
                        <div class="col-md-5">
                            <span class="form-label small d-block">{{ __('dpc.employee_details.cr.q_put_up') }}</span>
                            @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                                <label class="form-check form-check-inline small">
                                    <input class="form-check-input" type="radio" name="put_up" value="{{ $value }}" required
                                           @checked((string) old('put_up', $editCr?->put_up === null ? '' : (int) $editCr->put_up) === (string) $value)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <div class="col-md-5" data-show-when="put_up=1">
                            <span class="form-label small d-block">{{ __('dpc.employee_details.cr.q_grading') }}</span>
                            @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                                <label class="form-check form-check-inline small">
                                    <input class="form-check-input" type="radio" name="has_grading" value="{{ $value }}"
                                           @checked((string) old('has_grading', $editCr?->has_grading === null ? '' : (int) $editCr->has_grading) === (string) $value)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <p class="small text-muted mt-2 mb-1" data-show-when="put_up=0">{{ __('dpc.employee_details.cr.not_put_up_help') }}</p>
                    <p class="small text-muted mt-2 mb-1" data-show-when="has_grading=0">{{ __('dpc.employee_details.cr.no_grading_help') }}</p>
                    <div data-show-when="has_grading=1">
                        <p class="small text-muted mt-2 mb-1">{{ __('dpc.employee_details.cr.reports_help', ['max' => $maxReports]) }}</p>
                        @for ($i = 0; $i < $maxReports; $i++)
                            @php
                                $existingReport = $editCr ? ((array) $editCr->reports)[$i] ?? [] : [];
                            @endphp
                            <div class="row g-1 mb-1 small">
                                <div class="col-12 text-muted">{{ __('dpc.employee_details.cr.report_n', ['n' => $i + 1]) }}</div>
                                @foreach (['reporter', 'reviewer', 'accepting'] as $role)
                                    <div class="col-md-2">
                                        <input class="form-control form-control-sm" name="reports[{{ $i }}][{{ $role }}_designation]"
                                               placeholder="{{ __("dpc.employee_details.cr.{$role}_designation") }}" aria-label="{{ __("dpc.employee_details.cr.{$role}_designation") }}"
                                               value="{{ old("reports.{$i}.{$role}_designation", $existingReport["{$role}_designation"] ?? '') }}">
                                    </div>
                                    <div class="col-md-2">
                                        <select class="form-select form-select-sm" name="reports[{{ $i }}][{{ $role }}_grade]" aria-label="{{ __("dpc.employee_details.cr.{$role}_grade") }}">
                                            <option value="">{{ __("dpc.employee_details.cr.{$role}_grade") }}</option>
                                            @foreach ($crGrades as $grade)
                                                <option value="{{ $grade }}" @selected(old("reports.{$i}.{$role}_grade", $existingReport["{$role}_grade"] ?? '') === $grade)>{{ $grade }}@isset($gradePoints[$grade]) ({{ $gradePoints[$grade] }})@endisset</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        @endfor
                    </div>
                    <button class="btn btn-success btn-sm mt-2">{{ __('dpc.employee_details.cr.save_year') }}</button>
                </form>

                @if ($crYears->isNotEmpty())
                    <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 mb-3 row g-2 align-items-end">
                        @csrf
                        <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.cr.upload_heading') }}</h3>
                        <div class="col-md-3">
                            <label class="form-label small" for="cr-doc-kind">{{ __('dpc.employee_details.documents.kind') }}</label>
                            <select class="form-select form-select-sm" id="cr-doc-kind" name="kind" required>
                                @foreach (\App\Domain\Dpc\Enums\EvaluationDocumentKind::yearlyCrKinds() as $kind)
                                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="cr-doc-year">{{ __('ui.year') }}</label>
                            <select class="form-select form-select-sm" id="cr-doc-year" name="year" required>
                                @foreach ($crYears as $cr)<option value="{{ $cr->year }}">{{ $cr->year }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="cr-doc-file">{{ __('dpc.employee_details.documents.file') }}</label>
                            <input type="file" accept="application/pdf" class="form-control form-control-sm" id="cr-doc-file" name="document" required>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-outline-primary btn-sm w-100">{{ __('dpc.employee_details.documents.upload') }}</button>
                        </div>
                        <div class="col-12">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="undertaking" value="1">
                                {{ __('dpc.employee_details.cr.duplicate_undertaking') }}
                            </label>
                        </div>
                    </form>

                    <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="kind" value="cr_channel">
                        <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.cr.channel_heading') }}</h3>
                        <div class="col-12 small">
                            @if ($row->cr_channel_undertaking_at && ($channel = $docFor('cr_channel')))
                                <span class="badge text-bg-success">{{ __('dpc.employee_details.cr.channel_recorded', ['at' => $row->cr_channel_undertaking_at->format('d-m-Y H:i')]) }}</span>
                                <a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $channel]) }}">{{ $channel->original_name }}</a>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="undertaking" value="1" required>
                                {{ __('dpc.employee_details.cr.channel_undertaking') }}
                            </label>
                        </div>
                        <div class="col-12"><button class="btn btn-outline-primary btn-sm">{{ __('dpc.employee_details.documents.upload') }}</button></div>
                    </form>
                @endif
            @endif
        </div>
    </div>

    {{-- D. Property Return --}}
    <div class="card border-0 shadow-sm mb-3" id="section-property">
        <div class="card-body">
            <h2 class="h6">D. {{ __('dpc.employee_details.sections.property') }}</h2>
            <p class="small text-muted">{{ __('dpc.employee_details.property.lead', ['count' => $propertyYearsRequired]) }}</p>
            @error('property')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('late_decision')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('late_decision_remarks')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('ui.year') }}</th>
                        <th>{{ __('dpc.employee_details.property.submitted') }}</th>
                        <th>{{ __('dpc.employee_details.property.submitted_on') }}</th>
                        <th>{{ __('dpc.employee_details.property.next_increment') }}</th>
                        <th>{{ __('dpc.employee_details.property.late') }}</th>
                        <th>{{ __('dpc.employee_details.property.counts') }}</th>
                        <th>{{ __('dpc.employee_details.documents.heading') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($propertyYears as $property)
                            <tr data-property-year="{{ $property->year }}">
                                <td>{{ $property->year }}</td>
                                <td>{{ $property->submitted_on !== null ? __('ui.yes') : ($property->flag === null ? __('dpc.employee_details.not_answered') : __('ui.no')) }}</td>
                                <td>{{ $property->submitted_on?->format('d-m-Y') ?? '—' }}</td>
                                <td>{{ $property->next_increment_date?->format('d-m-Y') ?? '—' }}</td>
                                <td>
                                    @if ($property->isLate())
                                        {{ __('dpc.employee_details.property.late_yes') }}:
                                        {{ $property->late_decision ? __("dpc.employee_details.property.decisions.{$property->late_decision}") : __('dpc.employee_details.not_answered') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $property->flag === null ? '—' : ($property->flag ? __('ui.yes') : __('ui.no')) }}</td>
                                <td class="small">
                                    @foreach (['property_return', 'property_late_decision'] as $kind)
                                        @if ($document = $docFor($kind, $property->year))
                                            <div><a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $document]) }}">{{ $document->kind->label() }}</a></div>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEdit)
                                        <a class="btn btn-outline-primary btn-sm" href="{{ route('dpc-proposals.employees.show', [$proposal, $row, 'property_year' => $property->year]) }}#section-property">{{ __('ui.edit') }}</a>
                                        <form method="POST" class="d-inline" action="{{ route('dpc-proposals.employees.property.remove', [$proposal, $row, $property->year]) }}"
                                              data-confirm="{{ __('dpc.employee_details.remove_year_confirm', ['year' => $property->year]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">{{ __('dpc.employee_details.remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted text-center">{{ __('dpc.employee_details.property.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <form method="POST" action="{{ route('dpc-proposals.employees.property.save', [$proposal, $row]) }}" class="border rounded p-2 mb-3" data-employee-property-form>
                    @csrf
                    <h3 class="h6">{{ $editProperty ? __('dpc.employee_details.property.edit_year', ['year' => $editProperty->year]) : __('dpc.employee_details.property.add_year') }}</h3>
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small" for="property-year">{{ __('ui.year') }}</label>
                            <input type="number" class="form-control form-control-sm" id="property-year" name="year" required min="{{ config('dpc.evaluation.min_year') }}" max="{{ $latestYear }}"
                                   value="{{ old('year', $editProperty?->year) }}" @if ($editProperty) readonly @endif>
                        </div>
                        <div class="col-md-3">
                            <span class="form-label small d-block">{{ __('dpc.employee_details.property.q_submitted') }}</span>
                            @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                                <label class="form-check form-check-inline small">
                                    <input class="form-check-input" type="radio" name="submitted" value="{{ $value }}" required
                                           @checked((string) old('submitted', $editProperty === null || ($editProperty->flag === null && $editProperty->submitted_on === null) ? '' : (int) ($editProperty->submitted_on !== null)) === (string) $value)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <div class="col-md-3" data-show-when="submitted=1">
                            <label class="form-label small" for="submitted_on">{{ __('dpc.employee_details.property.submitted_on') }}</label>
                            <input type="date" class="form-control form-control-sm" id="submitted_on" name="submitted_on" value="{{ old('submitted_on', $editProperty?->submitted_on?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-4" data-show-when="submitted=1">
                            <label class="form-label small" for="next_increment_date">{{ __('dpc.employee_details.property.next_increment') }}</label>
                            <input type="date" class="form-control form-control-sm" id="next_increment_date" name="next_increment_date" value="{{ old('next_increment_date', $editProperty?->next_increment_date?->format('Y-m-d')) }}">
                            <div class="form-text">{{ __('dpc.employee_details.property.next_increment_help') }}</div>
                        </div>
                        <div class="col-md-4" data-show-when="submitted=1">
                            <label class="form-label small" for="late_decision">{{ __('dpc.employee_details.property.late_decision') }}</label>
                            <select class="form-select form-select-sm" id="late_decision" name="late_decision">
                                <option value="">—</option>
                                @foreach (['accepted', 'rejected'] as $decision)
                                    <option value="{{ $decision }}" @selected(old('late_decision', $editProperty?->late_decision) === $decision)>{{ __("dpc.employee_details.property.decisions.{$decision}") }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('dpc.employee_details.property.late_help') }}</div>
                        </div>
                        <div class="col-md-8" data-show-when="submitted=1">
                            <label class="form-label small" for="late_decision_remarks">{{ __('dpc.employee_details.property.late_reason') }}</label>
                            <input class="form-control form-control-sm" id="late_decision_remarks" name="late_decision_remarks" value="{{ old('late_decision_remarks', $editProperty?->late_decision_remarks) }}">
                        </div>
                    </div>
                    <button class="btn btn-success btn-sm mt-2">{{ __('dpc.employee_details.property.save_year') }}</button>
                </form>

                @if ($propertyYears->isNotEmpty())
                    <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 row g-2 align-items-end">
                        @csrf
                        <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.property.upload_heading') }}</h3>
                        <div class="col-md-3">
                            <label class="form-label small" for="property-doc-kind">{{ __('dpc.employee_details.documents.kind') }}</label>
                            <select class="form-select form-select-sm" id="property-doc-kind" name="kind" required>
                                @foreach ([\App\Domain\Dpc\Enums\EvaluationDocumentKind::PropertyReturn, \App\Domain\Dpc\Enums\EvaluationDocumentKind::PropertyLateDecision] as $kind)
                                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="property-doc-year">{{ __('ui.year') }}</label>
                            <select class="form-select form-select-sm" id="property-doc-year" name="year" required>
                                @foreach ($propertyYears as $property)<option value="{{ $property->year }}">{{ $property->year }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="property-doc-file">{{ __('dpc.employee_details.documents.file') }}</label>
                            <input type="file" accept="application/pdf" class="form-control form-control-sm" id="property-doc-file" name="document" required>
                        </div>
                        <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100">{{ __('dpc.employee_details.documents.upload') }}</button></div>
                    </form>
                @endif
            @endif
        </div>
    </div>

    {{-- E. PH reservation --}}
    <div class="card border-0 shadow-sm mb-3" id="section-ph">
        <div class="card-body">
            <h2 class="h6">E. {{ __('dpc.employee_details.sections.ph') }}</h2>
            <p class="small text-muted">{{ __('dpc.employee_details.ph.lead') }}</p>
            @error('ph')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($earlierPh->isNotEmpty())
                {{-- Rule 2.1: the system's check of an earlier PH record — reported, not applied. --}}
                <div class="alert alert-warning py-2 small" id="ph-earlier">
                    <div>{{ __('dpc.employee_details.ph.earlier_heading') }}</div>
                    <ul class="mb-0">
                        @foreach ($earlierPh as $earlier)
                            <li>{{ __('dpc.employee_details.ph.earlier_item', [
                                'proposal' => $earlier->proposal->dpc_code ?? ('#'.$earlier->proposal->id),
                                'year' => $earlier->proposal->dpc_year ?? '—',
                                'outcome' => $earlier->phOutcome()->label(),
                            ]) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <dl class="row small mb-2" id="ph-outcome">
                <dt class="col-sm-4">{{ __('dpc.employee_details.ph.outcome') }}</dt><dd class="col-sm-8">{{ $outcome->label() }}</dd>
                @if ($row->ph_ineligibility_reason)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.ph.reason') }}</dt><dd class="col-sm-8">{{ $row->ph_ineligibility_reason }}</dd>
                @endif
                <dt class="col-sm-4">{{ __('dpc.employee_details.ph.verification') }}</dt>
                <dd class="col-sm-8">{{ $row->ph_verification_status ? __("dpc.employee_details.ph.verification_states.{$row->ph_verification_status}") : '—' }}</dd>
                <dt class="col-sm-4">{{ __('dpc.employee_details.ph.final_decision') }}</dt>
                <dd class="col-sm-8">{{ $row->ph_final_decision ? __("dpc.employee_details.ph.decision_states.{$row->ph_final_decision}") : __('dpc.employee_details.ph.decision_states.open') }}</dd>
                @if ($row->ph_recorded_at)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.recorded_at') }}</dt><dd class="col-sm-8">{{ $row->ph_recorded_at->format('d-m-Y H:i') }}</dd>
                @endif
            </dl>

            @if ($canEdit)
                <form method="POST" action="{{ route('dpc-proposals.employees.ph', [$proposal, $row]) }}" class="border rounded p-2 mb-3" data-employee-ph-form>
                    @csrf @method('PUT')
                    <div class="mb-2">
                        <span class="form-label small d-block">Q1. {{ __('dpc.employee_details.ph.q1') }}</span>
                        @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                            <label class="form-check form-check-inline small">
                                <input class="form-check-input" type="radio" name="is_ph" value="{{ $value }}" required
                                       @checked((string) old('is_ph', $row->is_ph === null ? '' : (int) $row->is_ph) === (string) $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mb-2" data-show-when="is_ph=1">
                        <span class="form-label small d-block">Q2. {{ __('dpc.employee_details.ph.q2') }}</span>
                        @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                            <label class="form-check form-check-inline small">
                                <input class="form-check-input" type="radio" name="ph_previously_availed" value="{{ $value }}"
                                       @checked((string) old('ph_previously_availed', $row->ph_previously_availed === null ? '' : (int) $row->ph_previously_availed) === (string) $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                        <div class="form-text">{{ __('dpc.employee_details.ph.q2_help') }}</div>
                    </div>
                    <div class="mb-2" data-show-when="ph_previously_availed=1">
                        <label class="form-label small" for="ph_ineligibility_reason">{{ __('dpc.employee_details.ph.reason') }}</label>
                        <input class="form-control form-control-sm" id="ph_ineligibility_reason" name="ph_ineligibility_reason"
                               value="{{ old('ph_ineligibility_reason', $row->ph_ineligibility_reason) }}" placeholder="{{ __('dpc.employee_details.ph.default_previously_availed_reason') }}">
                    </div>
                    <div class="mb-2" data-show-when="ph_previously_availed=0">
                        <span class="form-label small d-block">Q3. {{ __('dpc.employee_details.ph.q3') }}</span>
                        @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                            <label class="form-check form-check-inline small">
                                <input class="form-check-input" type="radio" name="ph_claims_reservation" value="{{ $value }}"
                                       @checked((string) old('ph_claims_reservation', $row->ph_claims_reservation === null ? '' : (int) $row->ph_claims_reservation) === (string) $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                        <div class="form-text">{{ __('dpc.employee_details.ph.q3_help') }}</div>
                    </div>
                    <button class="btn btn-success btn-sm">{{ __('dpc.employee_details.save_section') }}</button>
                </form>
            @endif

            @if ($outcome->requiresUndertaking())
                <div class="border rounded p-2">
                    <h3 class="h6">{{ __('dpc.employee_details.ph.undertaking_heading') }}</h3>
                    <p class="small text-muted mb-2">{{ __('dpc.employee_details.ph.undertaking_lead') }}</p>
                    @if ($undertaking = $docFor('ph_undertaking'))
                        <p class="small mb-2" id="ph-undertaking-current">
                            <span class="badge text-bg-success">{{ __('dpc.employee_details.ph.undertaking_uploaded') }}</span>
                            <a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $undertaking]) }}">{{ $undertaking->original_name }}</a>
                            · {{ $undertaking->uploader?->name ?? '—' }} · {{ $undertaking->uploaded_at->format('d-m-Y H:i') }}
                        </p>
                    @else
                        <p class="small mb-2"><span class="badge text-bg-danger">{{ __('dpc.employee_details.missing.ph_undertaking') }}</span></p>
                    @endif
                    @if ($canEdit)
                        <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="row g-2 align-items-end">
                            @csrf
                            <input type="hidden" name="kind" value="ph_undertaking">
                            <div class="col-md-6">
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-check small">
                                    <input class="form-check-input" type="checkbox" name="undertaking" value="1" required>
                                    {{ __('dpc.employee_details.ph.undertaking_confirm') }}
                                </label>
                            </div>
                            <div class="col-12"><button class="btn btn-outline-primary btn-sm">{{ $undertaking ? __('dpc.employee_details.documents.replace') : __('dpc.employee_details.documents.upload') }}</button></div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- F. Undertakings and supporting documents — every version, history included. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-documents">
        <div class="card-body">
            <h2 class="h6">F. {{ __('dpc.employee_details.sections.documents') }}</h2>
            @error('documents')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('document')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('dpc.employee_details.documents.kind') }}</th>
                        <th>{{ __('ui.year') }}</th>
                        <th>{{ __('dpc.employee_details.documents.file') }}</th>
                        <th>{{ __('dpc.employee_details.documents.uploaded_by') }}</th>
                        <th>{{ __('dpc.employee_details.documents.state') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($row->documents->sortByDesc('id') as $document)
                            <tr>
                                <td>{{ $document->kind->label() }}@if ($document->remarks)<div class="small text-muted">{{ $document->remarks }}</div>@endif</td>
                                <td>{{ $document->year ?? '—' }}</td>
                                <td class="small">
                                    <a href="{{ route('dpc-proposals.employees.documents.download', [$proposal, $row, $document]) }}">{{ $document->original_name }}</a>
                                    <div class="text-muted font-monospace">{{ \Illuminate\Support\Str::limit($document->sha256, 16, '…') }}</div>
                                </td>
                                <td class="small">{{ $document->uploader?->name ?? '—' }}<div class="text-muted">{{ $document->uploaded_at->format('d-m-Y H:i') }}</div></td>
                                <td>
                                    <span class="badge {{ $document->isCurrent() ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $document->isCurrent() ? __('dpc.employee_details.documents.current') : __('dpc.employee_details.documents.superseded') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center">{{ __('dpc.employee_details.documents.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 row g-2 align-items-end">
                    @csrf
                    <input type="hidden" name="kind" value="other">
                    <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.documents.other_heading') }}</h3>
                    <div class="col-md-5">
                        <label class="form-label small" for="other-remarks">{{ __('dpc.employee_details.documents.description') }}</label>
                        <input class="form-control form-control-sm" id="other-remarks" name="remarks" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="other-file">{{ __('dpc.employee_details.documents.file') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm" id="other-file" name="document" required>
                    </div>
                    <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100">{{ __('dpc.employee_details.documents.upload') }}</button></div>
                </form>
            @endif
        </div>
    </div>

    <a href="{{ route('dpc-proposals.employees.index', $proposal) }}" class="btn btn-outline-secondary">{{ __('dpc.employee_details.back_to_list') }}</a>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-employee-details.js') }}" defer></script>
@endpush
