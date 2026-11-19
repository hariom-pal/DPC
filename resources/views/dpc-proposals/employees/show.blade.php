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
        // Exactly the five years before the DPC year, recorded or not (2026-11-14).
        $propertyWindow = array_reverse($propertyYearWindow);
        $propertyRows = collect(array_values(array_unique([...$propertyWindow, ...$propertyYears->pluck('year')->all()])))->sort()->values()
            ->map(fn (int $year) => ['year' => $year, 'record' => $propertyYears->firstWhere('year', $year)]);
        $addPropertyYear = $editProperty === null && in_array(request()->integer('property_year'), $propertyWindow, true) ? request()->integer('property_year') : null;
        $anyCrPutUp = $crYears->contains(fn ($cr) => $cr->put_up === true);
        $crGaps = $row->recordGaps->where('kind', 'cr')->keyBy('year');
        $crGapYears = $row->recordGaps->where('kind', 'cr')->filter(fn ($g) => $g->isJustified())->pluck('year')->all();
        $propertyGapYears = $row->recordGaps->where('kind', 'property')->filter(fn ($g) => $g->isJustified())->pluck('year')->all();
        $outcome = $row->phOutcome();
        $grader = app(\App\Domain\Dpc\Services\EmployeeDetailsService::class);
        $sectionLabels = [
            'basic' => __('dpc.employee_details.sections.basic'),
            'cr' => __('dpc.employee_details.sections.cr'),
            'property' => __('dpc.employee_details.sections.property'),
            ...($phApplies ? ['ph' => __('dpc.employee_details.sections.ph')] : []),
            'vigilance' => __('dpc.employee_details.sections.vigilance'),
            'declarations' => __('dpc.employee_details.sections.declarations'),
        ];
        // "Save & Next" (2026-11-14): each section's form names the section after it.
        $sectionOrder = ['basic', 'cr', 'property', ...($phApplies ? ['ph'] : []), 'vigilance', 'declarations', 'documents'];
        $nextSection = function (string $key) use ($sectionOrder): ?string {
            $i = array_search($key, $sectionOrder, true);

            return $i === false ? null : ($sectionOrder[$i + 1] ?? null);
        };
    @endphp

    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            @if ($atSitting)
                <li class="breadcrumb-item"><a href="{{ route('sitting.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('sitting.candidate', [$proposal, $row]) }}">{{ __('proceeding.sitting.record') }}</a></li>
            @else
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a>
            </li>
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.employees.index', $proposal) }}">{{ __('dpc.employee_details.index_title') }}</a>
            </li>
            @endif
            <li class="breadcrumb-item active" aria-current="page">{{ $row->name }}</li>
        </ol>
    </nav>

    <div id="employee-details" data-employee-details>
    @include('partials.alerts')
    @if ($atSitting)
        <div class="alert alert-warning py-2 small" id="chairman-sitting-edit">{{ __('proceeding.sitting.chairman_editing') }}</div>
    @endif

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

    {{-- MoM of 16 November 2026, point 3: a committee member's return opens only its sections. --}}
    @if ($committeeReturn)
        <div class="alert alert-warning py-2 small" id="committee-return-banner">
            <div class="fw-semibold">{{ __('dpc.committee_rejection.banner', ['member' => $committeeReturn['member'] ?? '—']) }}</div>
            <ul class="mb-0">
                {{-- This candidate's sections (2026-11-18), or every section of a return of the earlier kind. --}}
                @foreach ($proposal->committeeReturnCandidates()[$row->id] ?? (isset($committeeReturn['candidates']) ? [] : (array) ($committeeReturn['sections'] ?? [])) as $section => $remark)
                    <li data-returned-section="{{ $section }}"><strong>{{ __('dpc.committee_decision.sections.'.$section) }}:</strong> {{ $remark }}</li>
                @endforeach
            </ul>
            <div class="mt-1">{{ __('dpc.committee_decision.others_locked') }}</div>
        </div>
    @endif

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
            {{-- Everything the Seniority List CSV carries, here and read-only (2026-11-14):
                 it is the list's, corrected by uploading the list again, never typed here. --}}
            <p class="small text-muted mb-1">{{ __('dpc.employee_details.from_csv') }}</p>
            <dl class="row small mb-2" id="basic-from-csv">
                <dt class="col-sm-3">{{ __('dpc.employee_details.seniority_no') }}</dt><dd class="col-sm-9">{{ $row->sn }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.employee_id') }}</dt><dd class="col-sm-9">{{ $row->employee_number ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('ui.name') }}</dt><dd class="col-sm-9">{{ $row->name }}</dd>
                <dt class="col-sm-3">{{ __('ui.dob') }}</dt><dd class="col-sm-9">{{ $row->dob?->format('d-m-Y') }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.date_of_appointment') }}</dt><dd class="col-sm-9">{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.current_post') }}</dt><dd class="col-sm-9">{{ $row->promotion_from_post ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.posting_on_current_post') }}</dt><dd class="col-sm-9" id="basic-posting">{{ $row->date_of_posting_on_promotion_from_post?->format('d-m-Y') ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('dpc.employee_details.mobile') }}</dt><dd class="col-sm-9" id="basic-mobile">{{ $row->mobile ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('ui.qualification') }}</dt><dd class="col-sm-9" id="basic-qualification">{{ $row->qualification ?? '—' }}</dd>
            </dl>
            {{-- Saved: shown read-only until Edit is pressed (MoM of 12 November 2026, point 10). --}}
            <form method="POST" action="{{ route('dpc-proposals.employees.basic', [$proposal, $row]) }}" class="row g-2" data-ajax-section
                  @if (! $errors->any() && ($row->service_record_undertaking_at !== null || $row->dob_order_number !== null || $row->joining_order_number !== null)) data-saved-lock data-label-edit="{{ __('ui.edit') }}" data-label-cancel="{{ __('ui.cancel') }}" data-label-saved="{{ __('ui.saved_entry') }}" @endif>
                @csrf @method('PUT')
                @if (config('dpc.reservation.category_wise'))
                <div class="col-md-3">
                    <label class="form-label small" for="reservation_category_id">{{ __('ui.reservation_category') }}</label>
                    <select class="form-select form-select-sm" id="reservation_category_id" name="reservation_category_id" @disabled(! $canEditSection['basic'])>
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) old('reservation_category_id', $row->reservation_category_id) === $category->id)>{{ $category->code }} — {{ $category->name_en }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">{{ __('dpc.employee_details.category_help') }}</div>
                </div>
                @endif
                {{-- Rule 4.4: the orders behind the date of birth and of joining. --}}
                <div class="col-md-3">
                    <label class="form-label small" for="dob_order_number">{{ __('dpc.employee_details.service_record.dob_order_number') }} <span class="text-danger">*</span></label>
                    <input class="form-control form-control-sm @error('dob_order_number') is-invalid @enderror" required id="dob_order_number" name="dob_order_number" maxlength="120" value="{{ old('dob_order_number', $row->dob_order_number) }}" @disabled(! $canEditSection['basic'])>
                    @error('dob_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="dob_order_date">{{ __('dpc.employee_details.service_record.dob_order_date') }} <span class="text-danger">*</span></label>
                    <input type="date" class="form-control form-control-sm @error('dob_order_date') is-invalid @enderror" required id="dob_order_date" name="dob_order_date" value="{{ old('dob_order_date', $row->dob_order_date?->format('Y-m-d')) }}" @disabled(! $canEditSection['basic'])>
                    @error('dob_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="joining_order_number">{{ __('dpc.employee_details.service_record.joining_order_number') }} <span class="text-danger">*</span></label>
                    <input class="form-control form-control-sm @error('joining_order_number') is-invalid @enderror" required id="joining_order_number" name="joining_order_number" maxlength="120" value="{{ old('joining_order_number', $row->joining_order_number) }}" @disabled(! $canEditSection['basic'])>
                    @error('joining_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="joining_order_date">{{ __('dpc.employee_details.service_record.joining_order_date') }} <span class="text-danger">*</span></label>
                    <input type="date" class="form-control form-control-sm @error('joining_order_date') is-invalid @enderror" required id="joining_order_date" name="joining_order_date" value="{{ old('joining_order_date', $row->joining_order_date?->format('Y-m-d')) }}" @disabled(! $canEditSection['basic'])>
                    @error('joining_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <input type="hidden" name="service_record_undertaking" value="0">
                    <label class="form-check small">
                        <input class="form-check-input" type="checkbox" name="service_record_undertaking" value="1" id="service_record_undertaking" required
                               @checked(old('service_record_undertaking', $row->service_record_undertaking_at !== null)) @disabled(! $canEditSection['basic'])>
                        {{ __('dpc.employee_details.service_record.undertaking') }}
                    </label>
                    @error('service_record_undertaking')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                @if ($canEditSection['basic'])
                    <div class="col-12">@include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('basic')])</div>
                @endif
            </form>
        </div>
    </div>

    {{-- B. Seniority information — the list's, not editable here. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-seniority">
        <div class="card-body">
            <h2 class="h6">B. {{ __('dpc.employee_details.sections.seniority') }}</h2>
            <dl class="row small mb-0">
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
                    @if (in_array($ruleCheck['status'], ['meets', 'does_not_meet'], true))
                        <x-fit-mark :fit="$ruleCheck['status'] === 'meets'" data-rule-status="{{ $ruleCheck['status'] }}" />
                    @else
                        <span class="badge {{ ['cir' => 'text-bg-warning', 'no_rule' => 'text-bg-secondary'][$ruleCheck['status']] }}" data-rule-status="{{ $ruleCheck['status'] }}">{{ __('dpc.employee_details.rule.status.'.$ruleCheck['status']) }}</span>
                    @endif
                </div>
                @if ($ruleCheck['rule'] !== null)
                    <p class="small text-muted mb-2">{{ __('dpc.employee_details.rule.summary', [
                        'from' => $ruleCheck['rule']->from_class,
                        'to' => $ruleCheck['rule']->to_class,
                        'since' => $ruleCheck['rule']->effective_from_year,
                        'cr' => $ruleCheck['rule']->cr_years,
                        'total' => $ruleCheck['required_total'],
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
                                        <td>{{ $line['year'] }}@if ($line['cr_skipped'] ?? false)<div class="text-muted" data-rule-skipped>{{ __('dpc.employee_details.rule.skipped') }}</div>@endif
                                            @if ($line['cr_outside'] ?? false)<div class="text-muted" data-rule-outside>{{ __('dpc.employee_details.rule.outside') }}</div>@endif</td>
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
                    <p class="small mb-1" id="rule-total">{{ __('dpc.employee_details.rule.total', ['total' => $ruleCheck['total'] ?? '—', 'required' => $ruleCheck['required_total']]) }}</p>
                @endif
                @if (($ruleCheck['holds'] ?? []) !== [])
                    <ul class="small mb-2 text-warning-emphasis" id="rule-check-holds">
                        @foreach ($ruleCheck['holds'] as $hold)<li>{{ $hold }}</li>@endforeach
                    </ul>
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
                        <th>{{ __('dpc.employee_details.cr.integrity') }}</th>
                        <th>{{ __('dpc.employee_details.documents.heading') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($crYears as $cr)
                            <tr data-cr-year="{{ $cr->year }}" @class(['text-body-secondary' => $row->isBeforeAppointment((int) $cr->year)])>
                                <td>{{ $cr->year }}@if ($row->isBeforeAppointment((int) $cr->year))<div><span class="badge text-bg-secondary" data-before-appointment="{{ $cr->year }}">{{ __('dpc.employee_details.cr.before_appointment_badge') }}</span></div>@endif</td>
                                <td>
                                    {{ $cr->put_up === null ? __('dpc.employee_details.not_answered') : ($cr->put_up ? __('ui.yes') : __('ui.no')) }}
                                    {{-- Why it is missing, in the table (2026-11-14). --}}
                                    @if ($cr->put_up === false && ($gap = $crGaps->get($cr->year)))
                                        <div class="small" data-cr-missing-reason="{{ $cr->year }}">
                                            <span class="text-muted">{{ __('dpc.employee_details.cr.missing_reason') }}:</span> {{ $gap->reason?->label() ?? '—' }}
                                            @if ($gap->reason?->makes_unfit)<x-fit-mark :fit="false" />@endif
                                            @if ($gap->remarks)<div class="text-muted">{{ $gap->remarks }}</div>@endif
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $cr->has_grading === null ? '—' : ($cr->has_grading ? __('ui.yes') : __('ui.no')) }}</td>
                                <td class="small">
                                    @foreach ((array) $cr->reports as $report)
                                        <div>{{ $grader->finalGrade($report) }}
                                            @isset($report['authorities'])
                                                <span class="text-muted">({{ collect($report['authorities'])->map(fn ($role) => __("dpc.employee_details.cr.authority_{$role}"))->implode(', ') }})</span>
                                            @endisset
                                            @if ($grader->reportNeedsNrc($report))<span class="badge text-bg-light">{{ __('dpc.employee_details.cr.nrc_needed') }}</span>@endif
                                        </div>
                                    @endforeach
                                </td>
                                <td><strong>{{ $cr->grade ?? '—' }}</strong>@if ($cr->tribal_area === true) <span class="badge text-bg-info" data-cr-tribal="{{ $cr->year }}">{{ __('dpc.employee_details.cr.tribal_yes') }}</span>@endif</td>
                                <td class="small" data-cr-integrity="{{ $cr->year }}">
                                    @if ($cr->integrityOption)
                                        {{ $cr->integrityOption->label() }}
                                        @if ($cr->integrity_departmental_view)
                                            <div class="text-muted" data-cr-integrity-view="{{ $cr->year }}">{{ __('dpc.employee_details.cr.integrity_view') }}: {{ __('dpc.employee_details.cr.integrity_views.'.$cr->integrity_departmental_view) }}</div>
                                        @endif
                                        @if (($fit = $cr->integrityFit()) !== null)
                                            <x-fit-mark :fit="$fit" />
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small">
                                    {{-- Every CR of the year, each with its number when there are several (2026-11-16). --}}
                                    @php
                                        $yearCrs = $docs->filter(fn ($d) => $d->kind->value === 'cr_report' && $d->year === $cr->year)->sortBy(fn ($d) => $d->crNumber());
                                    @endphp
                                    @foreach ($yearCrs as $document)
                                        <div data-cr-document-number="{{ $cr->year }}-{{ $document->crNumber() }}">
                                            @if (count((array) $cr->reports) > 1)<span class="text-muted">{{ __('dpc.employee_details.cr.cr_number', ['number' => $document->crNumber()]) }}:</span>@endif
                                            @include('dpc-proposals.employees.partials.uploaded-document')
                                        </div>
                                    @endforeach
                                    @foreach (['cr_without_grading', 'cr_nrc', 'integrity_decision'] as $kind)
                                        @if ($document = $docFor($kind, $cr->year))
                                            @include('dpc-proposals.employees.partials.uploaded-document')
                                        @endif
                                    @endforeach
                                    {{-- The missing year's supporting document, beside the CRs' (2026-11-16). --}}
                                    @if ($cr->put_up === false && ($gap = $crGaps->get($cr->year)))
                                        @foreach (array_filter([null => $gap->hasDocument() ? [$gap->reason?->documentLabel() ?: __('dpc.employee_details.missing_record.document'), $gap->original_name] : null, 'second' => $gap->hasSecondDocument() ? [$gap->reason?->secondDocumentLabel(), $gap->second_original_name] : null]) as $part => [$label, $name])
                                            <div data-cr-gap-document="{{ $cr->year }}">
                                                <span class="badge text-bg-success">{{ __('dpc.employee_details.documents.uploaded') }}</span>
                                                <span class="text-muted">{{ $label }}:</span>
                                                <a href="{{ route('dpc-proposals.record-gaps.download', array_filter([$proposal, $row, $gap, 'part' => $part ?: null])) }}" target="_blank" rel="noopener">{{ $name }}</a>
                                            </div>
                                        @endforeach
                                    @endif
                                    @if ($cr->put_up === false && ! in_array($cr->year, $crGapYears, true))
                                        <div><a href="{{ route('dpc-proposals.record-gaps.index', [$proposal, $row]) }}">{{ __('dpc.employee_details.cr.record_reason') }}</a></div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEditSection['cr'])
                                        <a class="btn btn-outline-primary btn-sm" data-ajax-link href="{{ route('dpc-proposals.employees.show', [$proposal, $row, 'cr_year' => $cr->year]) }}#cr-form">{{ __('ui.edit') }}</a>
                                        <form method="POST" class="d-inline" data-ajax-section action="{{ route('dpc-proposals.employees.cr.remove', [$proposal, $row, $cr->year]) }}"
                                              data-confirm="{{ __('dpc.employee_details.remove_year_confirm', ['year' => $cr->year]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">{{ __('dpc.employee_details.remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted text-center">{{ __('dpc.employee_details.cr.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @php
                // At most the required CRs (2026-11-14): the years offered are the window —
                // five back from the year before the DPC year, one further for each year in
                // the middle missing or without grading — less those recorded. Once all are
                // recorded the form closes; a recorded year is changed with its Edit.
                $gradedCrYears = $crYears->filter(fn ($cr) => $cr->put_up === true && $cr->has_grading === true)->count();
                $crYearOptions = $editCr ? [$editCr->year] : array_values(array_diff($crYearWindow, $crYears->pluck('year')->all()));
                $showCrForm = $editCr !== null || $crYearOptions !== [];
            @endphp
            @if ($canEditSection['cr'] && ! $showCrForm)
                <p class="small text-success mb-3" id="cr-years-complete">{{ __('dpc.employee_details.cr.years_complete', ['count' => $gradedCrYears]) }}</p>
            @endif
            @if ($canEditSection['cr'])
                @if ($showCrForm)
                <form method="POST" action="{{ route('dpc-proposals.employees.cr.save', [$proposal, $row]) }}" class="border rounded p-2 mb-3" enctype="multipart/form-data" data-employee-cr-form data-ajax-section id="cr-form">
                    @csrf
                    <h3 class="h6">{{ $editCr ? __('dpc.employee_details.cr.edit_year', ['year' => $editCr->year]) : __('dpc.employee_details.cr.add_year') }}</h3>
                    <div class="row g-2">
                        {{-- The window's years not yet recorded (2026-11-14); a recorded year is changed with its Edit. --}}
                        <div class="col-md-2">
                            <label class="form-label small" for="cr-year">{{ __('ui.year') }}</label>
                            <select class="form-select form-select-sm" id="cr-year" name="year" required @if ($editCr) data-locked @endif>
                                @unless ($editCr)<option value="">{{ __('dpc.employee_details.cr.select_year') }}</option>@endunless
                                @foreach ($crYearOptions as $option)
                                    <option value="{{ $option }}" @selected((int) old('year', $editCr?->year) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
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
                        {{-- With a grading the CR counts; without one it is uploaded and does not
                             count, and one older year joins the window (2026-11-16). --}}
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
                    @include('dpc-proposals.employees.partials.missing-record', ['when' => 'put_up=0', 'years' => null, 'justified' => $crGapYears, 'prefix' => 'cr', 'reasons' => $reasons->filter(fn ($reason) => $reason->appliesTo('cr'))])
                    <p class="small text-muted mt-2 mb-1" data-show-when="has_grading=0">{{ __('dpc.employee_details.cr.no_grading_help') }}</p>
                    {{-- Up to four CRs a year, each with its authorities and grade (MoM of 12 November
                         2026); the year's NRC, where a CR lacks an authority's remarks, uploaded here. --}}
                    @php
                        $existingReports = $editCr ? array_values((array) $editCr->reports) : [];
                        $oldReports = (array) old('reports', []);
                        $crCount = (int) old('cr_count', max(1, count($existingReports)));
                    @endphp
                    <div data-show-when="has_grading=1" class="mt-1" data-cr-reports>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label small" for="cr-count">{{ __('dpc.employee_details.cr.cr_count') }}</label>
                                <select class="form-select form-select-sm" id="cr-count" name="cr_count" data-cr-count>
                                    @foreach (range(1, $maxReports) as $count)
                                        <option value="{{ $count }}" @selected($crCount === $count)>{{ $count }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-8 form-text align-self-end">{{ __('dpc.employee_details.cr.average_help') }}</div>
                        </div>
                        @foreach (range(0, $maxReports - 1) as $i)
                            @php
                                $existing = $existingReports[$i] ?? [];
                                $existingAuthorities = (array) ($existing['authorities'] ?? array_values(array_filter(
                                    \App\Domain\Dpc\Services\EmployeeDetailsService::CR_AUTHORITIES,
                                    fn ($role) => ($existing["{$role}_grade"] ?? null) !== null,
                                )));
                                $existingGrade = $existing === [] ? null : \App\Domain\Dpc\Models\DpcEvaluationCrGrade::finalGradeOf($existing);
                            @endphp
                            <div class="row g-2 border-top pt-2 mb-2" data-cr-report="{{ $i }}" @if ($i >= $crCount) hidden @endif>
                                <div class="col-12 small fw-semibold">{{ __('dpc.employee_details.cr.cr_number', ['number' => $i + 1]) }}</div>
                                <div class="col-md-7">
                                    <span class="form-label small d-block">{{ __('dpc.employee_details.cr.q_authorities') }}</span>
                                    @foreach (\App\Domain\Dpc\Services\EmployeeDetailsService::CR_AUTHORITIES as $role)
                                        <label class="form-check form-check-inline small">
                                            <input class="form-check-input" type="checkbox" name="reports[{{ $i }}][authorities][]" value="{{ $role }}" data-cr-authority
                                                   @checked(in_array($role, (array) ($oldReports[$i]['authorities'] ?? ($oldReports === [] ? $existingAuthorities : [])), true))>
                                            {{ __("dpc.employee_details.cr.authority_{$role}") }}
                                        </label>
                                    @endforeach
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="cr-grade-{{ $i }}">{{ __('dpc.employee_details.cr.grade_optional') }}</label>
                                    <select class="form-select form-select-sm" id="cr-grade-{{ $i }}" name="reports[{{ $i }}][grade]">
                                        <option value="">—</option>
                                        @foreach ($crGrades as $grade)
                                            <option value="{{ $grade }}" @selected(($oldReports[$i]['grade'] ?? ($oldReports === [] ? $existingGrade : null)) === $grade)>{{ $grade }}@isset($gradePoints[$grade]) ({{ $gradePoints[$grade] }})@endisset</option>
                                        @endforeach
                                    </select>
                                </div>
                                {{-- Posted in a tribal area for this CR (2026-11-18): each CR of a year is
                                     upgraded by the tribal rule on its own. --}}
                                @php
                                    $tribalAnswer = $oldReports[$i]['tribal_area'] ?? ($oldReports === [] ? ($existing['tribal_area'] ?? ($existing === [] ? null : $editCr?->tribal_area)) : null);
                                @endphp
                                <div class="col-12" data-cr-tribal-report="{{ $i }}">
                                    <span class="form-label small d-block">{{ __('dpc.employee_details.cr.q_tribal_cr') }}</span>
                                    @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                                        <label class="form-check form-check-inline small">
                                            <input class="form-check-input" type="radio" name="reports[{{ $i }}][tribal_area]" value="{{ $value }}" data-required-when-shown
                                                   @checked($tribalAnswer !== null && (string) (int) (bool) $tribalAnswer === (string) $value)>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                {{-- Each CR uploaded on its own (2026-11-16). --}}
                                @php
                                    $partDocument = $editCr ? $docs->first(fn ($d) => $d->kind->value === 'cr_report' && $d->year === $editCr->year && $d->crNumber() === $i + 1) : null;
                                @endphp
                                <div class="col-md-7" data-cr-part-document="{{ $i + 1 }}">
                                    <label class="form-label small" for="cr-document-{{ $i }}">{{ __('dpc.employee_details.cr.document_upload_numbered', ['number' => $i + 1]) }}</label>
                                    @if ($partDocument)
                                        <div class="small mb-1">@include('dpc-proposals.employees.partials.uploaded-document', ['document' => $partDocument])</div>
                                    @endif
                                    <input type="file" accept="application/pdf" class="form-control form-control-sm @error('cr_documents.'.$i) is-invalid @enderror" id="cr-document-{{ $i }}" name="cr_documents[{{ $i }}]" @unless ($partDocument) data-required-when-shown @endunless>
                                    @error('cr_documents.'.$i)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        @endforeach
                        <div class="form-text mb-2">{{ __('dpc.employee_details.cr.authorities_help') }}</div>
                        <div class="border rounded p-2 bg-body-tertiary" data-cr-nrc hidden>
                            <label class="form-label small fw-semibold" for="cr-nrc">{{ __('dpc.employee_details.cr.nrc_upload') }}</label>
                            <input type="file" accept="application/pdf" class="form-control form-control-sm @error('nrc') is-invalid @enderror" id="cr-nrc" name="nrc">
                            @error('nrc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('dpc.employee_details.cr.nrc_help') }}</div>
                        </div>
                    </div>
                    {{-- Integrity (सत्यनिष्ठा) of the year, beside the grade, from the Administrator's
                         options — each Fit or Unfit (2026-11-14). --}}
                    <div class="row g-2 mt-1" data-show-when="put_up=1" data-cr-integrity-field>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold" for="cr-integrity">{{ __('dpc.employee_details.cr.integrity') }}</label>
                            <select class="form-select form-select-sm @error('integrity_option_id') is-invalid @enderror" id="cr-integrity" name="integrity_option_id" data-required-when-shown>
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach ($integrityOptions as $integrity)
                                    <option value="{{ $integrity->id }}" @if ($integrity->needs_departmental_view) data-needs-view @endif @selected((string) old('integrity_option_id', $editCr?->integrity_option_id) === (string) $integrity->id)>{{ $integrity->label() }}</option>
                                @endforeach
                            </select>
                            @error('integrity_option_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- The Final Departmental View Regarding Integrity, with its document, where
                             the answer asks for it (2026-11-16); the view decides Fit or Unfit. --}}
                        <div class="col-md-8" data-integrity-view hidden>
                            <label class="form-label small fw-semibold" for="cr-integrity-view">{{ __('dpc.employee_details.cr.integrity_view') }}</label>
                            <select class="form-select form-select-sm @error('integrity_departmental_view') is-invalid @enderror" id="cr-integrity-view" name="integrity_departmental_view">
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach (['certified', 'not_certified'] as $view)
                                    <option value="{{ $view }}" @selected(old('integrity_departmental_view', $editCr?->integrity_departmental_view) === $view)>{{ __('dpc.employee_details.cr.integrity_views.'.$view) }}</option>
                                @endforeach
                            </select>
                            @error('integrity_departmental_view')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <label class="form-label small fw-semibold mt-2" for="cr-integrity-document">{{ __('dpc.employee_details.cr.integrity_document') }}</label>
                            @if ($editCr && ($document = $docFor('integrity_decision', $editCr->year)))
                                @include('dpc-proposals.employees.partials.uploaded-document')
                            @endif
                            <input type="file" accept="application/pdf,.pdf" class="form-control form-control-sm @error('integrity_document') is-invalid @enderror" id="cr-integrity-document" name="integrity_document">
                            @error('integrity_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    {{-- The CR itself, uploaded with its grading (2026-11-14): a graded CR as the CR,
                         one without grading as the CR without grading; there is no separate upload.
                         Both confirmations are mandatory with the file. --}}
                    @php
                        $currentCrDocument = $editCr ? $docFor('cr_without_grading', $editCr->year) : null;
                        $anyCrDocument = $editCr ? ($docFor('cr_report', $editCr->year) ?? $currentCrDocument) : null;
                    @endphp
                    <div class="border rounded p-2 mt-2 bg-body-tertiary" data-show-when="put_up=1" data-cr-document>
                        {{-- A graded year's CRs are uploaded one by one above (2026-11-16); this
                             file is the CR without grading. --}}
                        <div data-show-when="has_grading=0">
                            <label class="form-label small fw-semibold" for="cr-document">{{ __('dpc.employee_details.cr.document_upload') }}</label>
                            @if ($currentCrDocument)
                                <div class="small mb-1">@include('dpc-proposals.employees.partials.uploaded-document', ['document' => $currentCrDocument])</div>
                            @endif
                            <input type="file" accept="application/pdf" class="form-control form-control-sm @error('cr_document') is-invalid @enderror" id="cr-document" name="cr_document" @unless ($currentCrDocument) data-required-when-shown @endunless>
                            @error('cr_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('dpc.employee_details.cr.document_help') }}</div>
                        </div>
                        <label class="form-check small mt-1">
                            <input class="form-check-input @error('cr_verified') is-invalid @enderror" type="checkbox" name="cr_verified" value="1" @checked(old('cr_verified')) @unless ($anyCrDocument) data-required-when-shown @endunless>
                            {{ __('dpc.employee_details.verification.ao_confirm') }}
                        </label>
                        @error('cr_verified')<div class="text-danger small">{{ $message }}</div>@enderror
                        <label class="form-check small">
                            <input class="form-check-input @error('cr_undertaking') is-invalid @enderror" type="checkbox" name="cr_undertaking" value="1" @checked(old('cr_undertaking')) @unless ($anyCrDocument) data-required-when-shown @endunless>
                            {{ __('dpc.employee_details.cr.duplicate_undertaking') }}
                        </label>
                        @error('cr_undertaking')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="mt-2 d-flex gap-2">
                        <button class="btn btn-success btn-sm">{{ __('dpc.employee_details.cr.save_year') }}</button>
                        @if ($editCr)
                            <a class="btn btn-outline-secondary btn-sm" data-ajax-link href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}#section-cr">{{ __('ui.cancel') }}</a>
                        @endif
                    </div>
                </form>
                @endif

                {{-- The CR channel undertaking: once for the employee, not per CR (2026-11-14);
                     the document and both confirmations are mandatory. --}}
                @if ($anyCrPutUp)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 row g-2 align-items-end" id="cr-channel" data-ajax-section>
                        @csrf
                        <input type="hidden" name="kind" value="cr_channel">
                        <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.cr.channel_heading') }}</h3>
                        <div class="col-12 small">
                            @if ($row->cr_channel_undertaking_at && ($channel = $docFor('cr_channel')))
                                <span class="badge text-bg-success">{{ __('dpc.employee_details.cr.channel_recorded', ['at' => $row->cr_channel_undertaking_at->format('d-m-Y H:i')]) }}</span>
                                @include('dpc-proposals.employees.partials.uploaded-document', ['document' => $channel])
                            @endif
                        </div>
                        <div class="col-md-6">
                            <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}"><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="verified" value="1" required> {{ __('dpc.employee_details.verification.ao_confirm') }}</label>
                        </div>
                        <div class="col-md-6">
                            <label class="form-check small">
                                <input class="form-check-input" type="checkbox" name="undertaking" value="1" required>
                                {{ __('dpc.employee_details.cr.channel_undertaking') }}
                            </label>
                        </div>
                        <div class="col-12"><button class="btn btn-outline-primary btn-sm">{{ ($channel ?? null) ? __('dpc.employee_details.documents.replace') : __('dpc.employee_details.documents.upload') }}</button></div>
                    </form>
                @endif
                <div class="mt-2">@include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('cr'), 'only' => 'next'])</div>
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
                        @foreach ($propertyRows as $line)
                            @if ($line['record'] === null)
                                <tr data-property-year="{{ $line['year'] }}" data-property-missing class="table-warning">
                                    <td>{{ $line['year'] }}</td>
                                    <td colspan="6" class="small text-muted">{{ __('dpc.employee_details.property.not_recorded') }}</td>
                                    <td class="text-end text-nowrap">
                                        @if ($canEditSection['property'])
                                            <a class="btn btn-outline-success btn-sm" data-ajax-link href="{{ route('dpc-proposals.employees.show', [$proposal, $row, 'property_year' => $line['year']]) }}#property-form">{{ __('dpc.employee_details.property.record') }}</a>
                                        @endif
                                    </td>
                                </tr>
                                @continue
                            @endif
                            @php
                                $property = $line['record'];
                            @endphp
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
                                            @include('dpc-proposals.employees.partials.uploaded-document')
                                        @endif
                                    @endforeach
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($canEditSection['property'])
                                        <a class="btn btn-outline-primary btn-sm" data-ajax-link href="{{ route('dpc-proposals.employees.show', [$proposal, $row, 'property_year' => $property->year]) }}#property-form">{{ __('ui.edit') }}</a>
                                        <form method="POST" class="d-inline" data-ajax-section action="{{ route('dpc-proposals.employees.property.remove', [$proposal, $row, $property->year]) }}"
                                              data-confirm="{{ __('dpc.employee_details.remove_year_confirm', ['year' => $property->year]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">{{ __('dpc.employee_details.remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php
                // The last five years offered, without those already saved; once all are saved
                // the form is not shown — a saved year is changed with its Edit (2026-11-14).
                $propertyYearOptions = $editProperty ? [$editProperty->year] : array_values(array_diff(array_reverse($propertyWindow), $propertyYears->pluck('year')->all()));
            @endphp
            @if ($canEditSection['property'] && $propertyYearOptions === [])
                <p class="small text-success mb-3" id="property-years-complete">{{ __('dpc.employee_details.property.years_complete') }}</p>
            @endif
            @if ($canEditSection['property'])
                @if ($propertyYearOptions !== [])
                <form method="POST" action="{{ route('dpc-proposals.employees.property.save', [$proposal, $row]) }}" class="border rounded p-2 mb-3" enctype="multipart/form-data" data-employee-property-form data-ajax-section id="property-form">
                    @csrf
                    <h3 class="h6">{{ $editProperty ? __('dpc.employee_details.property.edit_year', ['year' => $editProperty->year]) : __('dpc.employee_details.property.add_year') }}</h3>
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small" for="property-year">{{ __('ui.year') }}</label>
                            <select class="form-select form-select-sm" id="property-year" name="year" required @if ($editProperty) data-locked @endif>
                                @unless ($editProperty)<option value="">{{ __('dpc.employee_details.cr.select_year') }}</option>@endunless
                                @foreach ($propertyYearOptions as $option)
                                    <option value="{{ $option }}" @selected((int) old('year', $editProperty?->year ?? $addPropertyYear) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
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
                            <input type="date" class="form-control form-control-sm @error('submitted_on') is-invalid @enderror" id="submitted_on" name="submitted_on" value="{{ old('submitted_on', $editProperty?->submitted_on?->format('Y-m-d')) }}" @if ($editProperty) min="{{ $editProperty->year }}-01-01" @endif data-min-from-year>
                            @error('submitted_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4" data-show-when="submitted=1">
                            <label class="form-label small" for="next_increment_date">{{ __('dpc.employee_details.property.next_increment') }}</label>
                            {{-- Always 1 January or 1 July of the next year: a 2025 file offers
                                 01-01-2026 and 01-07-2026 only (2026-11-16). --}}
                            @php
                                $incrementYear = (int) old('year', $editProperty?->year ?? $addPropertyYear);
                                $incrementValue = (string) old('next_increment_date', $editProperty?->next_increment_date?->format('Y-m-d'));
                            @endphp
                            <select class="form-select form-select-sm @error('next_increment_date') is-invalid @enderror" id="next_increment_date" name="next_increment_date" data-increment-from-year data-required-when-shown>
                                <option value="">{{ __('ui.select') }}</option>
                                @if ($incrementYear > 0)
                                    @foreach (\App\Domain\Dpc\Models\DpcEvaluationPropertyFlag::incrementDatesFor($incrementYear) as $date)
                                        <option value="{{ $date->format('Y-m-d') }}" @selected($incrementValue === $date->format('Y-m-d'))>{{ $date->format('d-m-Y') }}</option>
                                    @endforeach
                                @endif
                            </select>
                            @error('next_increment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('dpc.employee_details.property.next_increment_help') }}</div>
                        </div>
                        {{-- Asked only for a return submitted after 31 January of its own year — the 2025
                             return after 31 January 2025 (2026-11-14). --}}
                        <div class="col-md-4" data-show-when="submitted=1" data-show-if-late>
                            <label class="form-label small" for="late_decision">{{ __('dpc.employee_details.property.late_decision') }}</label>
                            <select class="form-select form-select-sm" id="late_decision" name="late_decision">
                                <option value="">—</option>
                                @foreach (['accepted', 'rejected'] as $decision)
                                    <option value="{{ $decision }}" @selected(old('late_decision', $editProperty?->late_decision) === $decision)>{{ __("dpc.employee_details.property.decisions.{$decision}") }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('dpc.employee_details.property.late_help') }}</div>
                        </div>
                        <div class="col-md-8" data-show-when="submitted=1" data-show-if-late>
                            <label class="form-label small" for="late_decision_remarks">{{ __('dpc.employee_details.property.late_reason') }}</label>
                            <input class="form-control form-control-sm" id="late_decision_remarks" name="late_decision_remarks" value="{{ old('late_decision_remarks', $editProperty?->late_decision_remarks) }}">
                        </div>
                        {{-- The department's decision document, with the year — no separate upload (2026-11-14). --}}
                        <div class="col-md-8" data-show-when="submitted=1" data-show-if-late data-property-late-document>
                            <label class="form-label small" for="late-document">{{ __('dpc.employee_details.property.late_upload_heading') }}</label>
                            @if ($editProperty && ($currentLate = $docFor('property_late_decision', $editProperty->year)))
                                <div class="small mb-1">@include('dpc-proposals.employees.partials.uploaded-document', ['document' => $currentLate])</div>
                            @endif
                            <input type="file" accept="application/pdf" class="form-control form-control-sm @error('late_document') is-invalid @enderror" id="late-document" name="late_document">
                            @error('late_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    @include('dpc-proposals.employees.partials.missing-record', ['when' => 'submitted=0', 'years' => $propertyRuleYears, 'justified' => $propertyGapYears, 'prefix' => 'property', 'reasons' => $reasons->filter(fn ($reason) => $reason->appliesTo('property'))])
                    {{-- The return itself, uploaded with its year — no separate upload (2026-11-14). --}}
                    <div class="border rounded p-2 mt-2 bg-body-tertiary" data-show-when="submitted=1" data-property-document>
                        <label class="form-label small fw-semibold" for="property-document">{{ __('dpc.employee_details.property.document_upload') }}</label>
                        @if ($editProperty && ($currentReturn = $docFor('property_return', $editProperty->year)))
                            <div class="small mb-1">@include('dpc-proposals.employees.partials.uploaded-document', ['document' => $currentReturn])</div>
                        @endif
                        <input type="file" accept="application/pdf" class="form-control form-control-sm @error('property_document') is-invalid @enderror" id="property-document" name="property_document" @unless ($editProperty && $docFor('property_return', $editProperty->year)) data-required-when-shown @endunless>
                        @error('property_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <label class="form-check small mt-1">
                            <input class="form-check-input @error('property_verified') is-invalid @enderror" type="checkbox" name="property_verified" value="1" @checked(old('property_verified')) @unless ($editProperty && $docFor('property_return', $editProperty->year)) data-required-when-shown @endunless>
                            {{ __('dpc.employee_details.verification.ao_confirm') }}
                        </label>
                        @error('property_verified')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="mt-2 d-flex gap-2">
                        <button class="btn btn-success btn-sm">{{ __('dpc.employee_details.property.save_year') }}</button>
                        @if ($editProperty)
                            <a class="btn btn-outline-secondary btn-sm" data-ajax-link href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}#section-property">{{ __('ui.cancel') }}</a>
                        @endif
                    </div>
                </form>
                @endif

                <div>@include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('property'), 'only' => 'next'])</div>
            @endif
        </div>
    </div>

    {{-- Shown only where the DPC's vacancies give a PH seat (MoM of 12 November 2026). --}}
    @if ($phApplies)
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
                @if ($row->is_ph)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.ph.type') }}</dt><dd class="col-sm-8" id="ph-type">{{ $row->phType?->label() ?? '—' }}</dd>
                @endif
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
                @if ($row->ph_verification_remarks)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.ph.verification_remarks') }}</dt><dd class="col-sm-8">{{ $row->ph_verification_remarks }}</dd>
                @endif
            </dl>

            {{-- Rule 2.1: the Administrative Head decides a claim before approving the proposal. --}}
            @if ($canVerify && $outcome->isClaim())
                <form method="POST" action="{{ route('dpc-proposals.employees.ph.verify', [$proposal, $row]) }}" class="border rounded p-2 mb-3" id="ph-verify-form">
                    @csrf
                    <span class="form-label small d-block">{{ __('dpc.employee_details.ph.verify_heading') }}</span>
                    @foreach (['verified', 'not_verified'] as $decision)
                        <label class="form-check form-check-inline small">
                            <input class="form-check-input" type="radio" name="decision" value="{{ $decision }}" required
                                   @checked(old('decision', in_array($row->ph_verification_status, ['verified', 'not_verified'], true) ? $row->ph_verification_status : '') === $decision)>
                            {{ __('dpc.employee_details.ph.verification_states.'.$decision) }}
                        </label>
                    @endforeach
                    <div class="mt-2">
                        <label class="form-label small" for="ph-verify-remarks">{{ __('dpc.employee_details.ph.verification_remarks') }}</label>
                        <textarea class="form-control form-control-sm @error('remarks') is-invalid @enderror" id="ph-verify-remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks', $row->ph_verification_remarks) }}</textarea>
                        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-success btn-sm mt-2">{{ __('dpc.employee_details.ph.verify_button') }}</button>
                </form>
            @endif

            @if ($canEditSection['ph'])
                <form method="POST" action="{{ route('dpc-proposals.employees.ph', [$proposal, $row]) }}" class="border rounded p-2 mb-3" data-employee-ph-form data-ajax-section
                      @if (! $errors->any() && $row->is_ph !== null) data-saved-lock data-label-edit="{{ __('ui.edit') }}" data-label-cancel="{{ __('ui.cancel') }}" data-label-saved="{{ __('ui.saved_entry') }}" @endif>
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
                    {{-- The kind of disability, from the PH Type master (2026-11-05). --}}
                    <div class="mb-2" data-show-when="is_ph=1">
                        <label class="form-label small d-block" for="ph_type_id">{{ __('dpc.employee_details.ph.type') }}</label>
                        <select class="form-select form-select-sm @error('ph_type_id') is-invalid @enderror" id="ph_type_id" name="ph_type_id" style="max-width:28rem">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach (\App\Domain\Master\Models\PhType::query()->selectable()->get() as $phType)
                                <option value="{{ $phType->id }}" @selected((string) old('ph_type_id', $row->ph_type_id) === (string) $phType->id)>{{ $phType->label() }}</option>
                            @endforeach
                        </select>
                        @error('ph_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    @include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('ph')])
                </form>
            @endif

            @if ($outcome->requiresUndertaking())
                <div class="border rounded p-2">
                    <h3 class="h6">{{ __('dpc.employee_details.ph.undertaking_heading') }}</h3>
                    <p class="small text-muted mb-2">{{ __('dpc.employee_details.ph.undertaking_lead') }}</p>
                    @if ($undertaking = $docFor('ph_undertaking'))
                        <p class="small mb-2" id="ph-undertaking-current">
                            <span class="badge text-bg-success">{{ __('dpc.employee_details.ph.undertaking_uploaded') }}</span>
                            @include('dpc-proposals.employees.partials.uploaded-document', ['document' => $undertaking])
                            · {{ $undertaking->uploader?->name ?? '—' }} · {{ $undertaking->uploaded_at->format('d-m-Y H:i') }}
                        </p>
                    @else
                        <p class="small mb-2"><span class="badge text-bg-danger">{{ __('dpc.employee_details.missing.ph_undertaking') }}</span></p>
                    @endif
                    @if ($canEditSection['ph'])
                        <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="row g-2 align-items-end" data-ajax-section>
                            @csrf
                            <input type="hidden" name="kind" value="ph_undertaking">
                            <div class="col-md-6">
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}"><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="verified" value="1" required> {{ __('dpc.employee_details.verification.ao_confirm') }}</label>
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


    @endif
    {{-- Rule 2.2: departmental enquiry and the sealed cover. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-vigilance">
        <div class="card-body">
            <h2 class="h6">{{ __('dpc.employee_details.sections.vigilance') }}</h2>
            <p class="small text-muted">{{ __('dpc.employee_details.vigilance.lead') }}</p>
            @error('vigilance')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <dl class="row small mb-2" id="vigilance-outcome">
                @foreach (['enquiry_ongoing' => 'enquiry_question_short', 'punishment_effective' => 'punishment_question_short'] as $field => $labelKey)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.vigilance.'.$labelKey) }}</dt>
                    <dd class="col-sm-8" data-vigilance-answer="{{ $field }}">
                        {{ $row->{$field} === null ? '—' : ($row->{$field} ? __('ui.yes') : __('ui.no')) }}
                        {{-- The first question's Yes is the sealed cover (2026-11-18); the second's is Unfit. --}}
                        @if ($row->{$field} && $field === 'enquiry_ongoing')<span class="badge text-bg-warning" data-sealed-cover="yes">{{ __('dpc.employee_details.vigilance.sealed_cover') }}</span>
                        @elseif ($row->{$field})<x-fit-mark :fit="false" />@endif
                    </dd>
                @endforeach
                {{-- A "matter pending" recorded before the question was retired (2026-11-18). --}}
                @if ($row->vigilance_pending && $row->sealedCoverReason)
                    <dt class="col-sm-4">{{ __('dpc.employee_details.vigilance.answer') }}</dt>
                    <dd class="col-sm-8">{{ __('ui.yes') }}</dd>
                    <dt class="col-sm-4">{{ __('dpc.employee_details.vigilance.reason') }}</dt>
                    <dd class="col-sm-8">{{ $row->sealedCoverReason->label() }}
                        <span class="badge {{ $row->isUnderSealedCover() ? 'text-bg-danger' : 'text-bg-secondary' }}" data-sealed-cover="{{ $row->isUnderSealedCover() ? 'yes' : 'no' }}">{{ __('admin.sealed_cover_reasons.effects.'.$row->sealedCoverReason->effect) }}</span>
                    </dd>
                    <dt class="col-sm-4">{{ __('dpc.employee_details.vigilance.date') }}</dt><dd class="col-sm-8">{{ $row->vigilance_date?->format('d-m-Y') ?? '—' }}</dd>
                    @if ($row->vigilance_remarks)
                        <dt class="col-sm-4">{{ __('ui.remarks') }}</dt><dd class="col-sm-8">{{ $row->vigilance_remarks }}</dd>
                    @endif
                @endif
            </dl>

            @if ($canEditSection['vigilance'])
                <form method="POST" action="{{ route('dpc-proposals.employees.vigilance', [$proposal, $row]) }}" class="border rounded p-2 mb-3" enctype="multipart/form-data" data-ajax-section id="vigilance-form"
                      @if (! $errors->any() && $row->enquiry_ongoing !== null && $row->punishment_effective !== null) data-saved-lock data-label-edit="{{ __('ui.edit') }}" data-label-cancel="{{ __('ui.cancel') }}" data-label-saved="{{ __('ui.saved_entry') }}" @endif>
                    @csrf @method('PUT')
                    {{-- The two questions of 2026-11-14, for the DPC's period. A Yes makes the officer
                         Unfit and asks for its document; a No asks for none (2026-11-16). --}}
                    @php
                        $period = ['from' => $enquiryPeriod['from']->format('d-m-Y'), 'to' => $enquiryPeriod['to']->format('d-m-Y')];
                    @endphp
                    @foreach (['enquiry_ongoing' => ['enquiry_question', 'enquiry_document'], 'punishment_effective' => ['punishment_question', 'punishment_document']] as $field => [$questionKey, $documentKind])
                        <div class="border rounded p-2 mb-2" data-unfit-question="{{ $field }}">
                            <span class="form-label small d-block fw-semibold">{{ $loop->iteration }}. {{ __('dpc.employee_details.vigilance.'.$questionKey, $period) }}</span>
                            @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                                <label class="form-check form-check-inline small">
                                    <input class="form-check-input" type="radio" name="{{ $field }}" value="{{ $value }}" required
                                           @checked((string) old($field, $row->{$field} === null ? '' : (int) $row->{$field}) === (string) $value)>
                                    {{ $label }}
                                </label>
                            @endforeach
                            @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('dpc.employee_details.vigilance.'.($field === 'enquiry_ongoing' ? 'sealed_help' : 'unfit_help')) }}</div>
                            <div class="mt-1" data-show-when="{{ $field }}=1" data-vigilance-document="{{ $field }}">
                                <label class="form-label small" for="{{ $documentKind }}">{{ __('dpc.employee_details.documents.kinds.'.$documentKind) }}</label>
                                @if ($existingDocument = $docFor($documentKind))
                                    <div class="small mb-1">@include('dpc-proposals.employees.partials.uploaded-document', ['document' => $existingDocument])</div>
                                @endif
                                <input type="file" accept="application/pdf" class="form-control form-control-sm @error($documentKind) is-invalid @enderror" id="{{ $documentKind }}" name="{{ $documentKind }}" @unless ($existingDocument) data-required-when-shown @endunless>
                                @error($documentKind)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @endforeach
                    {{-- Asked with a document only: the server refuses a file without it. --}}
                    <label class="form-check small mb-2">
                        <input class="form-check-input @error('vigilance_verified') is-invalid @enderror" type="checkbox" name="vigilance_verified" value="1" @checked(old('vigilance_verified'))>
                        {{ __('dpc.employee_details.verification.ao_confirm') }}
                    </label>
                    @error('vigilance_verified')<div class="text-danger small">{{ $message }}</div>@enderror
                    {{-- "Is any enquiry, charge-sheet, show-cause notice or suspension pending?" is
                         no longer asked (2026-11-18): the first question's Yes is the sealed cover. --}}
                    @include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('vigilance')])
                </form>
            @endif

            @if ($row->vigilance_pending)
                <div class="border rounded p-2">
                    <h3 class="h6">{{ __('dpc.employee_details.vigilance.order_heading') }}</h3>
                    @if ($vigilanceOrder = $docFor('vigilance_order'))
                        <p class="small mb-2" id="vigilance-order-current">
                            <span class="badge text-bg-success">{{ __('dpc.employee_details.vigilance.order_uploaded') }}</span>
                            @include('dpc-proposals.employees.partials.uploaded-document', ['document' => $vigilanceOrder])
                            · {{ $vigilanceOrder->uploader?->name ?? '—' }} · {{ $vigilanceOrder->uploaded_at->format('d-m-Y H:i') }}
                        </p>
                    @elseif ($row->sealedCoverReason?->requires_document)
                        <p class="small mb-2"><span class="badge text-bg-danger">{{ __('dpc.employee_details.missing.vigilance_order') }}</span></p>
                    @endif
                    @if ($canEditSection['vigilance'])
                        <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="row g-2 align-items-end" data-ajax-section>
                            @csrf
                            <input type="hidden" name="kind" value="vigilance_order">
                            <div class="col-md-6">
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}"><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="verified" value="1" required> {{ __('dpc.employee_details.verification.ao_confirm') }}</label>
                            </div>
                            <div class="col-md-6"><button class="btn btn-outline-primary btn-sm">{{ $vigilanceOrder ? __('dpc.employee_details.documents.replace') : __('dpc.employee_details.documents.upload') }}</button></div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Rule 2.3 and rule 4.4: deputation without a sanctioned setup; promotion refused. --}}
    <div class="card border-0 shadow-sm mb-3" id="section-declarations">
        <div class="card-body">
            <h2 class="h6">{{ __('dpc.employee_details.sections.declarations') }}</h2>
            @error('declarations')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @if ($row->refusedPromotionLastYear() || $row->unsanctioned_deputation)
                <div class="alert alert-warning py-2 small" id="declarations-not-eligible">{{ __('dpc.employee_details.declarations.not_eligible') }}</div>
            @endif

            @if ($canEditSection['declarations'])
                <form method="POST" action="{{ route('dpc-proposals.employees.declarations', [$proposal, $row]) }}" class="border rounded p-2 mb-3" data-ajax-section
                      @if (! $errors->any() && $row->unsanctioned_deputation !== null) data-saved-lock data-label-edit="{{ __('ui.edit') }}" data-label-cancel="{{ __('ui.cancel') }}" data-label-saved="{{ __('ui.saved_entry') }}" @endif>
                    @csrf @method('PUT')
                    <div class="mb-2">
                        <span class="form-label small d-block">{{ __('dpc.employee_details.declarations.deputation_question') }}</span>
                        @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                            <label class="form-check form-check-inline small">
                                <input class="form-check-input" type="radio" name="unsanctioned_deputation" value="{{ $value }}" required
                                       @checked((string) old('unsanctioned_deputation', $row->unsanctioned_deputation === null ? '' : (int) $row->unsanctioned_deputation) === (string) $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mb-2">
                        <span class="form-label small d-block">{{ __('dpc.employee_details.declarations.refused_question') }}</span>
                        @foreach ([1 => __('ui.yes'), 0 => __('ui.no')] as $value => $label)
                            <label class="form-check form-check-inline small">
                                <input class="form-check-input" type="radio" name="refused_promotion" value="{{ $value }}" required
                                       @checked((string) old('refused_promotion', (int) $row->refused_promotion) === (string) $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mb-2 col-md-3" data-show-when="refused_promotion=1">
                        <label class="form-label small" for="refused_promotion_year">{{ __('dpc.employee_details.declarations.refused_year') }}</label>
                        <input type="number" class="form-control form-control-sm @error('refused_promotion_year') is-invalid @enderror" id="refused_promotion_year" name="refused_promotion_year"
                               min="1950" max="{{ $latestYear }}" value="{{ old('refused_promotion_year', $row->refused_promotion_year) }}">
                        @error('refused_promotion_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @include('dpc-proposals.employees.partials.save-buttons', ['next' => $nextSection('declarations')])
                </form>
            @endif

            @php
                $declarationDocuments = array_values(array_filter([
                    $row->unsanctioned_deputation ? 'deputation_order' : null,
                    $row->refused_promotion ? 'refusal_letter' : null,
                    $row->refused_promotion ? 'refusal_dpc_order' : null,
                ]));
            @endphp
            @foreach ($declarationDocuments as $declarationKind)
                <div class="border rounded p-2 mb-2" data-declaration-document="{{ $declarationKind }}">
                    <h3 class="h6">{{ __('dpc.employee_details.documents.kinds.'.$declarationKind) }}</h3>
                    @if ($declarationDocument = $docFor($declarationKind))
                        <p class="small mb-2">
                            <span class="badge text-bg-success">{{ __('dpc.employee_details.vigilance.order_uploaded') }}</span>
                            @include('dpc-proposals.employees.partials.uploaded-document', ['document' => $declarationDocument])
                        </p>
                    @else
                        <p class="small mb-2"><span class="badge text-bg-danger">{{ __('dpc.employee_details.declarations.document_missing') }}</span></p>
                    @endif
                    @if ($canEditSection['declarations'])
                        <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="row g-2 align-items-end" data-ajax-section>
                            @csrf
                            <input type="hidden" name="kind" value="{{ $declarationKind }}">
                            <div class="col-md-6">
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" name="document" required aria-label="{{ __('dpc.employee_details.documents.file') }}"><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="verified" value="1" required> {{ __('dpc.employee_details.verification.ao_confirm') }}</label>
                            </div>
                            <div class="col-md-6"><button class="btn btn-outline-primary btn-sm">{{ $declarationDocument ? __('dpc.employee_details.documents.replace') : __('dpc.employee_details.documents.upload') }}</button></div>
                        </form>
                    @endif
                </div>
            @endforeach
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
                        <th>{{ __('dpc.employee_details.verification.column') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($row->documents->sortByDesc('id') as $document)
                            <tr>
                                <td>{{ $document->kind->label() }}@if ($document->remarks)<div class="small text-muted">{{ $document->remarks }}</div>@endif</td>
                                <td>{{ $document->year ?? '—' }}</td>
                                <td class="small">
                                    @include('dpc-proposals.employees.partials.uploaded-document', ['document' => $document])
                                    <div class="text-muted font-monospace">{{ \Illuminate\Support\Str::limit($document->sha256, 16, '…') }}</div>
                                </td>
                                <td class="small">{{ $document->uploader?->name ?? '—' }}<div class="text-muted">{{ $document->uploaded_at->format('d-m-Y H:i') }}</div></td>
                                <td>
                                    <span class="badge {{ $document->isCurrent() ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $document->isCurrent() ? __('dpc.employee_details.documents.current') : __('dpc.employee_details.documents.superseded') }}
                                    </span>
                                </td>
                                {{-- Rule 4.4: the AO's verification on upload, the AH's before approval. --}}
                                <td class="small" data-document-verification="{{ $document->uuid }}">
                                    <div>{{ __('dpc.employee_details.verification.ao') }}: {!! $document->ao_verified_at ? '✓ '.e($document->ao_verified_at->format('d-m-Y')) : '—' !!}</div>
                                    <div>{{ __('dpc.employee_details.verification.ah') }}: {!! $document->ah_verified_at ? '✓ '.e($document->ah_verified_at->format('d-m-Y')) : '—' !!}</div>
                                    @if ($canVerify && $document->isCurrent() && $document->ah_verified_at === null)
                                        <form method="POST" action="{{ route('dpc-proposals.employees.documents.verify', [$proposal, $row, $document]) }}">
                                            @csrf
                                            <button class="btn btn-outline-success btn-sm py-0">{{ __('dpc.employee_details.verification.verify') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">{{ __('dpc.employee_details.documents.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canVerify && $row->currentDocuments->whereNull('ah_verified_at')->isNotEmpty())
                <form method="POST" action="{{ route('dpc-proposals.employees.documents.verify-all', [$proposal, $row]) }}" class="mb-3">
                    @csrf
                    <button class="btn btn-success btn-sm" id="verify-all-documents">{{ __('dpc.employee_details.verification.verify_all') }}</button>
                    <span class="small text-muted ms-2">{{ __('dpc.employee_details.verification.verify_all_help') }}</span>
                </form>
            @endif

            @if ($canEditSection['documents'])
                <form method="POST" enctype="multipart/form-data" action="{{ route('dpc-proposals.employees.documents.store', [$proposal, $row]) }}" class="border rounded p-2 row g-2 align-items-end" data-ajax-section>
                    @csrf
                    <input type="hidden" name="kind" value="other">
                    <h3 class="h6 col-12 mb-0">{{ __('dpc.employee_details.documents.other_heading') }}</h3>
                    <div class="col-md-5">
                        <label class="form-label small" for="other-remarks">{{ __('dpc.employee_details.documents.description') }}</label>
                        <input class="form-control form-control-sm" id="other-remarks" name="remarks" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="other-file">{{ __('dpc.employee_details.documents.file') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm" id="other-file" name="document" required><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="verified" value="1" required> {{ __('dpc.employee_details.verification.ao_confirm') }}</label>
                    </div>
                    <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100">{{ __('dpc.employee_details.documents.upload') }}</button></div>
                </form>
            @endif
        </div>
    </div>

    {{-- One officer after another (2026-11-16): Submit & Next only once this officer is
         complete; on the last officer, Complete & Submit once every officer is, which
         opens the proposal's submission (signed with eSign there). --}}
    <div class="d-flex flex-wrap align-items-center gap-2" id="employee-flow">
        <a href="{{ route('dpc-proposals.employees.index', $proposal) }}" class="btn btn-outline-secondary">{{ __('dpc.employee_details.back_to_list') }}</a>
        @if ($canEdit)
            @if ($nextRow)
                @if ($status['complete'])
                    <a href="{{ route('dpc-proposals.employees.show', [$proposal, $nextRow]) }}" class="btn btn-success ms-auto" data-employee-flow="next">{{ __('dpc.employee_details.flow.submit_next') }}</a>
                @else
                    <button type="button" class="btn btn-success ms-auto" disabled data-employee-flow="next">{{ __('dpc.employee_details.flow.submit_next') }}</button>
                    <div class="w-100 text-end small text-danger">{{ __('dpc.employee_details.flow.finish_this_first', ['pending' => $status['summary']]) }}</div>
                @endif
            @elseif ($status['complete'] && $pendingCount === 0)
                <a href="{{ route('dpc-proposals.show', $proposal) }}#dpc-submit" class="btn btn-success ms-auto" data-employee-flow="complete">{{ __('dpc.employee_details.flow.complete_submit') }}</a>
            @else
                <button type="button" class="btn btn-success ms-auto" disabled data-employee-flow="complete">{{ __('dpc.employee_details.flow.complete_submit') }}</button>
                <div class="w-100 text-end small text-danger">{{ $status['complete']
                    ? __('dpc.employee_details.flow.finish_all_first', ['count' => $pendingCount])
                    : __('dpc.employee_details.flow.finish_this_first', ['pending' => $status['summary']]) }}</div>
            @endif
        @endif
    </div>
    </div>
@endsection

@push('scripts')
    <script src="@versioned('js/forms/dpc-employee-details.js')" defer></script>
    <script src="@versioned('js/forms/cr-reports.js')" defer></script>
    <script src="@versioned('js/forms/dpc-employee-ajax.js')" defer></script>
@endpush
