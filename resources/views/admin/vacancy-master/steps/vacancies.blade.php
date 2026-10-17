{{-- Step 3. Vacancies during the year, one line per reason, each with its order. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>3. {{ __('vacancymaster.entries.heading') }}</strong></div>
    <div class="card-body pb-0">
        <p class="small text-muted mb-2">{{ __('vacancymaster.steps.vacancies_intro', ['year' => $year]) }}</p>
    </div>
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

                    <details class="col-12 border rounded p-2" @if (old('higher_post_id') || old('higher_dpc_reference') || old('promotion_order_number')) open @endif>
                        <summary class="small fw-semibold">{{ __('vacancymaster.entries.higher_heading') }} — {{ __('vacancymaster.steps.more_details') }}</summary>
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
                    </details>

                    <details class="col-12 border rounded p-2" @if (old('deputation_to') || old('deputation_expected_months') || old('against_sanctioned_post') !== null || old('cadre_details')) open @endif>
                        <summary class="small fw-semibold">{{ __('vacancymaster.entries.deputation_heading') }} — {{ __('vacancymaster.steps.more_details') }}</summary>
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
                    </details>

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
