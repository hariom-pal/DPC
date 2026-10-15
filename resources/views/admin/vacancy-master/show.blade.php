@extends('layouts.admin')

@section('title', __('vacancymaster.title').' — '.$post->name)

@section('content')
    @php
        $isDraft = $position !== null && $position->isDraft();
        $isLatestFinalized = $position !== null && $position->status->value === 'finalized';
        $working = $position?->workingStrength;
        $b = $breakdown;
    @endphp

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.index') }}">{{ __('vacancymaster.department_management') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.edit', $department) }}">{{ $department->name }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.vacancy-master.index', [$department, 'year' => $year]) }}">{{ __('vacancymaster.title') }} {{ $year }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $post->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $post->name }}</h1>
            <div class="text-muted small">
                {{ __('vacancymaster.department_label') }}: <strong>{{ $department->name }}</strong>
                · {{ __('vacancymaster.vacancy_year') }}: <strong>{{ $year }}</strong>
                · {{ __('vacancymaster.year_window', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}
            </div>
            <div class="text-muted small">{{ __('vacancymaster.seniority_reference', ['date' => $seniorityReference->format('d-m-Y')]) }}</div>
        </div>
        <div class="text-end">
            @if ($position !== null)
                <span class="badge {{ $position->status->value === 'finalized' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $position->status->label() }}</span>
                <div class="small text-muted">{{ __('vacancymaster.revision_n', ['revision' => $position->revision]) }}</div>
            @endif
        </div>
    </div>

    @include('partials.alerts')

    @if ($position === null)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <p class="mb-2">{{ __('vacancymaster.no_position_yet', ['year' => $year]) }}</p>
                <form method="POST" action="{{ route('admin.departments.vacancy-master.open', [$department, $year, $post]) }}">
                    @csrf
                    <button class="btn btn-primary btn-sm">{{ __('vacancymaster.open_draft') }}</button>
                </form>
            </div>
        </div>
    @else
        @if ($position->revision_reason)
            <div class="alert alert-info small">{{ __('vacancymaster.revision_reason_label') }}: {{ $position->revision_reason }}</div>
        @endif

        {{-- The calculation, and what still stops it from being finalized. --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>{{ __('vacancymaster.calc.heading') }}</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tbody>
                        <tr><td>{{ __('vacancymaster.calc.sanctioned') }}</td><td class="text-end">{{ $b['sanctioned'] ?? __('vacancymaster.not_recorded') }}</td></tr>
                        <tr><td>{{ __('vacancymaster.calc.working') }}</td><td class="text-end">{{ $b['working']['total'] ?? __('vacancymaster.not_entered') }}</td></tr>
                        <tr class="table-light"><td>{{ __('vacancymaster.calc.opening') }}</td><td class="text-end">{{ $b['opening_vacancy'] ?? '—' }}</td></tr>
                        <tr><td>{{ __('vacancymaster.calc.rule') }}</td>
                            <td class="text-end">
                                @if ($b['recruitment_rule_id'] === null)
                                    {{ __('vacancymaster.not_recorded') }}
                                @else
                                    {{ __('vacancymaster.calc.rule_value', ['version' => $b['recruitment_rule_version'], 'percentage' => $b['promotion_percentage'], 'rounding' => __('vacancymaster.rounding.'.$b['rounding'])]) }}
                                @endif
                            </td></tr>
                        @if ($b['opening_quota'] !== null && $b['opening_quota']['promotion_chairs'] !== null)
                            <tr><td>{{ __('vacancymaster.calc.chairs') }}</td><td class="text-end">{{ __('vacancymaster.calc.chairs_value', ['promotion' => $b['opening_quota']['promotion_chairs'], 'direct' => $b['opening_quota']['direct_chairs'], 'exact' => number_format((float) $b['opening_quota']['promotion_exact'], 2)]) }}</td></tr>
                            <tr><td>{{ __('vacancymaster.calc.opening_quota') }}</td><td class="text-end">{{ __('vacancymaster.calc.quota_value', ['promotion' => $b['opening_quota']['promotion_vacancy'], 'direct' => $b['opening_quota']['direct_vacancy']]) }}</td></tr>
                        @endif
                        <tr><td>{{ __('vacancymaster.calc.existing_entries') }}</td><td class="text-end">{{ $b['existing_entries_total'] }}</td></tr>
                        <tr><td>{{ __('vacancymaster.calc.anticipated_counted') }}</td><td class="text-end">{{ __('vacancymaster.calc.quota_value', ['promotion' => $b['anticipated_counted_promotion'], 'direct' => $b['anticipated_counted_direct']]) }}</td></tr>
                        <tr><td>{{ __('vacancymaster.calc.anticipated_not_counted') }}</td><td class="text-end">{{ __('vacancymaster.calc.not_counted_value', ['opening' => $b['anticipated_already_in_opening'], 'pending' => $b['anticipated_pending'], 'not_eligible' => $b['anticipated_not_eligible']]) }}</td></tr>
                        <tr class="table-success fw-semibold">
                            <td>{{ __('vacancymaster.calc.final') }}</td>
                            <td class="text-end">
                                @if ($b['final_quota'] !== null && $b['final_quota']['promotion_vacancy'] !== null)
                                    {{ __('vacancymaster.calc.quota_value', ['promotion' => $b['final_quota']['promotion_vacancy'], 'direct' => $b['final_quota']['direct_vacancy']]) }}
                                @else
                                    {{ __('vacancymaster.incomplete') }}
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="small text-muted mb-2">{{ __('vacancymaster.calc.formula') }}</p>
                <p class="small text-muted mb-2">{{ __('vacancymaster.calc.reconciliation_limit') }}</p>
                @if ($b['issues'] !== [])
                    <div class="alert alert-warning small mb-0">
                        <strong>{{ __('vacancymaster.calc.issues_heading') }}</strong>
                        <ul class="mb-0">
                            @foreach ($b['issues'] as $issue)
                                <li>{{ __('vacancymaster.issues.'.$issue['key'], $issue['params']) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        {{-- Working strength as on 1 January. --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>{{ __('vacancymaster.working.heading', ['date' => '01-01-'.$year]) }}</strong></div>
            <div class="card-body">
                @if ($working === null)
                    <p class="text-muted small">{{ __('vacancymaster.working.not_entered_help') }}</p>
                @else
                    <dl class="row small mb-3">
                        <dt class="col-sm-4">{{ __('vacancymaster.working.total') }}</dt><dd class="col-sm-8">{{ $working->working_total }}</dd>
                        <dt class="col-sm-4">{{ __('vacancymaster.working.split') }}</dt>
                        <dd class="col-sm-8">
                            @if ($working->hasQuotaSplit())
                                {{ __('vacancymaster.working.split_value', ['promotion' => $working->working_promotion, 'direct' => $working->working_direct, 'deputation' => $working->working_deputation]) }}
                            @else
                                {{ __('vacancymaster.not_entered') }}
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('vacancymaster.order') }}</dt>
                        <dd class="col-sm-8">
                            {{ $working->order_number }} · {{ $working->order_date->format('d-m-Y') }} ·
                            <a href="{{ route('admin.departments.vacancy-master.working-strength.document', [$department, $year, $post, $working]) }}">{{ $working->original_name }}</a>
                        </dd>
                        @if ($working->remarks)
                            <dt class="col-sm-4">{{ __('vacancymaster.remarks') }}</dt><dd class="col-sm-8">{{ $working->remarks }}</dd>
                        @endif
                    </dl>
                @endif

                @if ($isDraft)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.departments.vacancy-master.working-strength', [$department, $year, $post]) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label small" for="working_total">{{ __('vacancymaster.working.total') }}</label>
                                <input type="number" min="0" class="form-control form-control-sm" id="working_total" name="working_total" value="{{ old('working_total', $working?->working_total) }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="working_promotion">{{ __('vacancymaster.working.promotion') }}</label>
                                <input type="number" min="0" class="form-control form-control-sm" id="working_promotion" name="working_promotion" value="{{ old('working_promotion', $working?->working_promotion) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="working_direct">{{ __('vacancymaster.working.direct') }}</label>
                                <input type="number" min="0" class="form-control form-control-sm" id="working_direct" name="working_direct" value="{{ old('working_direct', $working?->working_direct) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="working_deputation">{{ __('vacancymaster.working.deputation') }}</label>
                                <input type="number" min="0" class="form-control form-control-sm" id="working_deputation" name="working_deputation" value="{{ old('working_deputation', $working?->working_deputation) }}">
                            </div>
                            <div class="col-12"><div class="form-text">{{ __('vacancymaster.working.split_help') }}</div></div>
                            <div class="col-md-4">
                                <label class="form-label small" for="order_number">{{ __('vacancymaster.order_number') }}</label>
                                <input type="text" maxlength="120" class="form-control form-control-sm" id="order_number" name="order_number" value="{{ old('order_number') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="order_date">{{ __('vacancymaster.order_date') }}</label>
                                <input type="date" class="form-control form-control-sm" id="order_date" name="order_date" value="{{ old('order_date') }}" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="ws_document">{{ __('vacancymaster.document') }}</label>
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" id="ws_document" name="document" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small" for="ws_remarks">{{ __('vacancymaster.remarks') }}</label>
                                <textarea class="form-control form-control-sm" id="ws_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                            </div>
                            @if ($working !== null)
                                <div class="col-md-6">
                                    <label class="form-label small" for="correction_reason">{{ __('vacancymaster.working.correction_reason') }}</label>
                                    <textarea class="form-control form-control-sm" id="correction_reason" name="correction_reason" rows="2" maxlength="2000" required>{{ old('correction_reason') }}</textarea>
                                </div>
                            @endif
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="exceeds_sanctioned_acknowledged" name="exceeds_sanctioned_acknowledged">
                                    <label class="form-check-label small" for="exceeds_sanctioned_acknowledged">{{ __('vacancymaster.working.exceeds_ack') }}</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary btn-sm">{{ $working === null ? __('vacancymaster.working.save') : __('vacancymaster.working.correct') }}</button>
                            </div>
                        </div>
                    </form>
                @endif

                @if ($position->workingStrengthHistory->isNotEmpty())
                    <h3 class="h6 mt-3">{{ __('vacancymaster.working.history') }}</h3>
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0">
                            <thead><tr>
                                <th>{{ __('vacancymaster.working.total') }}</th><th>{{ __('vacancymaster.working.split') }}</th>
                                <th>{{ __('vacancymaster.order') }}</th><th>{{ __('vacancymaster.working.correction_reason') }}</th>
                                <th>{{ __('vacancymaster.by') }}</th><th>{{ __('vacancymaster.at') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($position->workingStrengthHistory as $row)
                                    <tr>
                                        <td>{{ $row->working_total }}</td>
                                        <td>{{ $row->hasQuotaSplit() ? $row->working_promotion.' / '.$row->working_direct.' / '.$row->working_deputation : '—' }}</td>
                                        <td>{{ $row->order_number }} · <a href="{{ route('admin.departments.vacancy-master.working-strength.document', [$department, $year, $post, $row]) }}">{{ $row->original_name }}</a></td>
                                        <td>{{ $row->correction_reason ?? '—' }}</td>
                                        <td>{{ $row->recorder?->name ?? '—' }}</td>
                                        <td>{{ $row->recorded_at->format('d-m-Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Reason-wise vacancies. --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>{{ __('vacancymaster.entries.heading') }}</strong></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead><tr>
                        <th>{{ __('vacancymaster.entries.kind') }}</th><th>{{ __('vacancymaster.entries.reason') }}</th>
                        <th class="text-end">{{ __('vacancymaster.entries.count') }}</th><th>{{ __('vacancymaster.entries.date') }}</th>
                        <th>{{ __('vacancymaster.entries.quota') }}</th><th>{{ __('vacancymaster.entries.reconciliation') }}</th>
                        <th>{{ __('vacancymaster.entries.eligibility') }}</th><th>{{ __('vacancymaster.order') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($position->entries as $entry)
                            <tr @class(['text-muted' => ! $entry->isActive()])>
                                <td>{{ $entry->kind->label() }}</td>
                                <td>
                                    {{ $entry->reason->label() }}
                                    @if ($entry->higherPost)
                                        <div>{{ __('vacancymaster.entries.higher_summary', ['post' => $entry->higherPost->name, 'reference' => $entry->higher_dpc_reference]) }}</div>
                                        @if ($entry->higherDpcProposal)
                                            <div>{{ __('vacancymaster.entries.higher_in_portal', ['status' => $entry->higherDpcProposal->status->value]) }}</div>
                                        @endif
                                    @endif
                                    @if ($entry->reason->value === 'deputation')
                                        <div>{{ __('vacancymaster.entries.deputation_summary', [
                                            'to' => $entry->deputation_to?->format('d-m-Y') ?? '—',
                                            'months' => $entry->deputation_expected_months ?? '—',
                                            'setup' => $entry->against_sanctioned_post === null ? '—' : ($entry->against_sanctioned_post ? __('vacancymaster.yes') : __('vacancymaster.no')),
                                        ]) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">{{ $entry->vacancy_count }}</td>
                                <td>{{ $entry->effective_date->format('d-m-Y') }}</td>
                                <td>{{ $entry->occupant_quota ? __('vacancymaster.quota.'.$entry->occupant_quota) : '—' }}</td>
                                <td>{{ $entry->reconciliation?->label() ?? '—' }}</td>
                                <td>
                                    @if (! $entry->isActive())
                                        <span class="badge text-bg-secondary">{{ __('vacancymaster.entries.withdrawn') }}</span>
                                        <div>{{ $entry->withdrawal_reason }}</div>
                                    @else
                                        <span @class(['badge',
                                            'text-bg-success' => $entry->isCounted(),
                                            'text-bg-warning' => $entry->eligibility->value === 'pending_verification',
                                            'text-bg-light border' => ! $entry->isCounted() && $entry->eligibility->value !== 'pending_verification',
                                        ])>{{ $entry->eligibility->label() }}</span>
                                        @if ($entry->isCounted())<div>{{ __('vacancymaster.entries.counted') }}</div>@endif
                                        @if ($entry->eligibility_note)<div class="text-muted">{{ $entry->eligibility_note }}</div>@endif
                                        @if ($entry->verified_at)
                                            <div>{{ __('vacancymaster.entries.verified_by', ['name' => $entry->verifier?->name ?? '—', 'at' => $entry->verified_at->format('d-m-Y H:i'), 'remarks' => $entry->verification_remarks]) }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td>{{ $entry->order_number }} · <a href="{{ route('admin.departments.vacancy-master.entries.document', [$department, $year, $post, $entry]) }}">{{ $entry->original_name }}</a></td>
                                <td class="text-end">
                                    @if ($isDraft && $entry->isActive())
                                        @if ($entry->eligibility->value === 'pending_verification')
                                            <form method="POST" class="mb-1 text-start" action="{{ route('admin.departments.vacancy-master.entries.verify', [$department, $year, $post, $entry]) }}">
                                                @csrf
                                                <select name="decision" class="form-select form-select-sm mb-1" required>
                                                    <option value="eligible">{{ __('vacancymaster.eligibility.eligible') }}</option>
                                                    <option value="not_eligible">{{ __('vacancymaster.eligibility.not_eligible') }}</option>
                                                </select>
                                                <select name="basis" class="form-select form-select-sm mb-1">
                                                    <option value="">{{ __('vacancymaster.entries.choose_basis') }}</option>
                                                    @foreach ($entry->reason->verificationBases() as $basis)
                                                        <option value="{{ $basis }}">{{ __('vacancymaster.bases.'.$basis) }}</option>
                                                    @endforeach
                                                </select>
                                                @if ($entry->reason->value === 'higher_post_promotion')
                                                    <input type="text" name="promotion_order_number" class="form-control form-control-sm mb-1" placeholder="{{ __('vacancymaster.entries.promotion_order_number') }}" value="{{ $entry->promotion_order_number }}">
                                                    <input type="date" name="promotion_order_date" class="form-control form-control-sm mb-1" value="{{ $entry->promotion_order_date?->toDateString() }}">
                                                @endif
                                                <textarea name="remarks" class="form-control form-control-sm mb-1" rows="2" required placeholder="{{ __('vacancymaster.entries.verification_remarks') }}"></textarea>
                                                <button class="btn btn-outline-success btn-sm">{{ __('vacancymaster.entries.verify') }}</button>
                                            </form>
                                        @endif
                                        <form method="POST" class="text-start" action="{{ route('admin.departments.vacancy-master.entries.withdraw', [$department, $year, $post, $entry]) }}">
                                            @csrf
                                            <input type="text" name="withdrawal_reason" class="form-control form-control-sm mb-1" required placeholder="{{ __('vacancymaster.entries.withdrawal_reason') }}">
                                            <button class="btn btn-outline-danger btn-sm">{{ __('vacancymaster.entries.withdraw') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-3">{{ __('vacancymaster.entries.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($isDraft)
                <div class="card-body border-top">
                    <h3 class="h6">{{ __('vacancymaster.entries.add_heading') }}</h3>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.departments.vacancy-master.entries.store', [$department, $year, $post]) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label small" for="kind">{{ __('vacancymaster.entries.kind') }}</label>
                                <select class="form-select form-select-sm" id="kind" name="kind" required>
                                    @foreach ($kinds as $kind)
                                        <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>{{ $kind->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="reason">{{ __('vacancymaster.entries.reason') }}</label>
                                <select class="form-select form-select-sm" id="reason" name="reason" required>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->value }}" @selected(old('reason') === $reason->value)>{{ $reason->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small" for="vacancy_count">{{ __('vacancymaster.entries.count') }}</label>
                                <input type="number" min="1" class="form-control form-control-sm" id="vacancy_count" name="vacancy_count" value="{{ old('vacancy_count') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="effective_date">{{ __('vacancymaster.entries.date') }}</label>
                                <input type="date" class="form-control form-control-sm" id="effective_date" name="effective_date" value="{{ old('effective_date') }}" required>
                                <div class="form-text">{{ __('vacancymaster.entries.date_help', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="occupant_quota">{{ __('vacancymaster.entries.quota') }}</label>
                                <select class="form-select form-select-sm" id="occupant_quota" name="occupant_quota">
                                    <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                                    <option value="promotion" @selected(old('occupant_quota') === 'promotion')>{{ __('vacancymaster.quota.promotion') }}</option>
                                    <option value="direct" @selected(old('occupant_quota') === 'direct')>{{ __('vacancymaster.quota.direct') }}</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="reconciliation">{{ __('vacancymaster.entries.reconciliation') }}</label>
                                <select class="form-select form-select-sm" id="reconciliation" name="reconciliation">
                                    <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                                    @foreach ($reconciliations as $reconciliation)
                                        <option value="{{ $reconciliation->value }}" @selected(old('reconciliation') === $reconciliation->value)>{{ $reconciliation->label() }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('vacancymaster.entries.reconciliation_help') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="entry_order_number">{{ __('vacancymaster.order_number') }}</label>
                                <input type="text" maxlength="120" class="form-control form-control-sm" id="entry_order_number" name="order_number" value="{{ old('order_number') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="entry_order_date">{{ __('vacancymaster.order_date') }}</label>
                                <input type="date" class="form-control form-control-sm" id="entry_order_date" name="order_date" value="{{ old('order_date') }}" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="entry_document">{{ __('vacancymaster.document') }}</label>
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" id="entry_document" name="document" required>
                            </div>

                            <fieldset class="col-12 border rounded p-2">
                                <legend class="fs-6 small mb-1">{{ __('vacancymaster.entries.higher_heading') }}</legend>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small" for="higher_post_id">{{ __('vacancymaster.entries.higher_post') }}</label>
                                        <select class="form-select form-select-sm" id="higher_post_id" name="higher_post_id">
                                            <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                                            @foreach ($higherPosts as $higher)
                                                <option value="{{ $higher->id }}" @selected((int) old('higher_post_id') === $higher->id)>{{ $higher->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small" for="higher_dpc_reference">{{ __('vacancymaster.entries.higher_dpc_reference') }}</label>
                                        <input type="text" maxlength="60" class="form-control form-control-sm" id="higher_dpc_reference" name="higher_dpc_reference" value="{{ old('higher_dpc_reference') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small" for="promotion_order_number">{{ __('vacancymaster.entries.promotion_order_number') }}</label>
                                        <input type="text" maxlength="120" class="form-control form-control-sm" id="promotion_order_number" name="promotion_order_number" value="{{ old('promotion_order_number') }}">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small" for="promotion_order_date">{{ __('vacancymaster.entries.promotion_order_date') }}</label>
                                        <input type="date" class="form-control form-control-sm" id="promotion_order_date" name="promotion_order_date" value="{{ old('promotion_order_date') }}">
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="col-12 border rounded p-2">
                                <legend class="fs-6 small mb-1">{{ __('vacancymaster.entries.deputation_heading') }}</legend>
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label small" for="deputation_to">{{ __('vacancymaster.entries.deputation_to') }}</label>
                                        <input type="date" class="form-control form-control-sm" id="deputation_to" name="deputation_to" value="{{ old('deputation_to') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small" for="deputation_expected_months">{{ __('vacancymaster.entries.deputation_months') }}</label>
                                        <input type="number" min="1" max="120" class="form-control form-control-sm" id="deputation_expected_months" name="deputation_expected_months" value="{{ old('deputation_expected_months') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small" for="against_sanctioned_post">{{ __('vacancymaster.entries.against_sanctioned_post') }}</label>
                                        <select class="form-select form-select-sm" id="against_sanctioned_post" name="against_sanctioned_post">
                                            <option value="">{{ __('vacancymaster.entries.not_stated') }}</option>
                                            <option value="1" @selected(old('against_sanctioned_post') === '1')>{{ __('vacancymaster.yes') }}</option>
                                            <option value="0" @selected(old('against_sanctioned_post') === '0')>{{ __('vacancymaster.no') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small" for="cadre_details">{{ __('vacancymaster.entries.cadre_details') }}</label>
                                        <input type="text" maxlength="255" class="form-control form-control-sm" id="cadre_details" name="cadre_details" value="{{ old('cadre_details') }}">
                                    </div>
                                </div>
                            </fieldset>

                            <div class="col-12">
                                <label class="form-label small" for="entry_remarks">{{ __('vacancymaster.remarks') }}</label>
                                <textarea class="form-control form-control-sm" id="entry_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                            </div>
                            <div class="col-12"><button class="btn btn-primary btn-sm">{{ __('vacancymaster.entries.add') }}</button></div>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        {{-- Finalize, or correct a finalized position by revision. --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                @if ($isDraft)
                    <form method="POST" action="{{ route('admin.departments.vacancy-master.finalize', [$department, $year, $post]) }}" data-confirm="{{ __('vacancymaster.finalize_confirm') }}">
                        @csrf
                        <button class="btn btn-success btn-sm" @disabled(! $b['complete'])>{{ __('vacancymaster.finalize') }}</button>
                        @unless ($b['complete'])
                            <span class="small text-muted ms-2">{{ __('vacancymaster.finalize_blocked') }}</span>
                        @endunless
                    </form>
                @elseif ($isLatestFinalized)
                    <p class="small mb-2">{{ __('vacancymaster.finalized_note', ['name' => $position->finalizer?->name ?? '—', 'at' => $position->finalized_at?->format('d-m-Y H:i') ?? '—']) }}</p>
                    <form method="POST" action="{{ route('admin.departments.vacancy-master.revise', [$department, $year, $post]) }}">
                        @csrf
                        <label class="form-label small" for="revision_reason">{{ __('vacancymaster.revision_reason_label') }}</label>
                        <textarea class="form-control form-control-sm mb-2" id="revision_reason" name="revision_reason" rows="2" maxlength="2000" required></textarea>
                        <button class="btn btn-outline-warning btn-sm">{{ __('vacancymaster.revise') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    {{-- Every revision of this position, newest first. --}}
    @if ($revisions->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>{{ __('vacancymaster.history_heading') }}</strong></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead><tr>
                        <th>{{ __('vacancymaster.col.revision') }}</th><th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.sanctioned') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.existing') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.promotion') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.direct') }}</th>
                        <th>{{ __('vacancymaster.col.finalized') }}</th><th>{{ __('vacancymaster.revision_reason_label') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($revisions as $revision)
                            <tr>
                                <td>{{ $revision->revision }}</td>
                                <td>{{ $revision->status->label() }}</td>
                                <td class="text-end">{{ $revision->sanctioned_strength ?? '—' }}</td>
                                <td class="text-end">{{ $revision->opening_vacancy ?? '—' }}</td>
                                <td class="text-end">{{ $revision->promotion_vacancy ?? '—' }}</td>
                                <td class="text-end">{{ $revision->direct_vacancy ?? '—' }}</td>
                                <td>{{ $revision->finalized_at ? $revision->finalized_at->format('d-m-Y H:i').' · '.($revision->finalizer?->name ?? '—') : '—' }}</td>
                                <td>{{ $revision->revision_reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
