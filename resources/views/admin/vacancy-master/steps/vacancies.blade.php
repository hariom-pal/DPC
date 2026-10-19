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
                        <td>{{ $entry->order_number }} · <a href="{{ route($records.'.entries.document', [$department, $year, $post, $entry]) }}">{{ $entry->original_name }}</a></td>
                        <td class="text-end">
@php
    $entryBag = $errors->getBag('entry_'.$entry->id);
    $entryFocus = collect(['decision', 'basis', 'promotion_order_number', 'promotion_order_date', 'remarks', 'withdrawal_reason'])->first(fn ($field) => $entryBag->has($field));
    $entryOld = fn (string $key, $default = null) => (string) old('_entry') === (string) $entry->id ? old($key, $default) : $default;
@endphp
                            @if ($editable && $entry->isActive())
                                @if ($entry->eligibility->value === 'pending_verification')
                                    <form method="POST" class="mb-1 text-start" action="{{ route($records.'.entries.verify', [$department, $year, $post, $entry]) }}">
                                        @csrf
                                        <input type="hidden" name="_entry" value="{{ $entry->id }}">
@include('admin.vacancy-master.partials.field-error', ['field' => 'workflow', 'bag' => 'entry_'.$entry->id])
                                        <select name="decision" class="form-select form-select-sm mb-1 @error('decision', 'entry_'.$entry->id) is-invalid @enderror" required @if ($entryFocus === 'decision') autofocus @endif>
                                            <option value="eligible" @selected($entryOld('decision') === 'eligible')>{{ __('vacancymaster.eligibility.eligible') }}</option>
                                            <option value="not_eligible" @selected($entryOld('decision') === 'not_eligible')>{{ __('vacancymaster.eligibility.not_eligible') }}</option>
                                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'decision', 'bag' => 'entry_'.$entry->id])
                                        <select name="basis" class="form-select form-select-sm mb-1 @error('basis', 'entry_'.$entry->id) is-invalid @enderror" @if ($entryFocus === 'basis') autofocus @endif>
                                            <option value="">{{ __('vacancymaster.entries.choose_basis') }}</option>
                                            @foreach ($entry->reason->verificationBases() as $basis)
                                                <option value="{{ $basis }}" @selected($entryOld('basis') === $basis)>{{ __('vacancymaster.bases.'.$basis) }}</option>
                                            @endforeach
                                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'basis', 'bag' => 'entry_'.$entry->id])
                                        @if ($entry->reason->value === 'higher_post_promotion')
                                            <input type="text" name="promotion_order_number" class="form-control form-control-sm mb-1 @error('promotion_order_number', 'entry_'.$entry->id) is-invalid @enderror" placeholder="{{ __('vacancymaster.entries.promotion_order_number') }}" value="{{ $entryOld('promotion_order_number', $entry->promotion_order_number) }}" @if ($entryFocus === 'promotion_order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'promotion_order_number', 'bag' => 'entry_'.$entry->id])
                                            <input type="date" name="promotion_order_date" class="form-control form-control-sm mb-1 @error('promotion_order_date', 'entry_'.$entry->id) is-invalid @enderror" value="{{ $entryOld('promotion_order_date', $entry->promotion_order_date?->toDateString()) }}" @if ($entryFocus === 'promotion_order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'promotion_order_date', 'bag' => 'entry_'.$entry->id])
                                        @endif
                                        <textarea name="remarks" class="form-control form-control-sm mb-1 @error('remarks', 'entry_'.$entry->id) is-invalid @enderror" rows="2" required placeholder="{{ __('vacancymaster.entries.verification_remarks') }}" @if ($entryFocus === 'remarks') autofocus @endif>{{ $entryOld('remarks') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'remarks', 'bag' => 'entry_'.$entry->id])
                                        <button class="btn btn-outline-success btn-sm">{{ __('vacancymaster.entries.verify') }}</button>
                                    </form>
                                @endif
                                <form method="POST" class="text-start" action="{{ route($records.'.entries.withdraw', [$department, $year, $post, $entry]) }}">
                                    @csrf
                                    <input type="hidden" name="_entry" value="{{ $entry->id }}">
                                    <input type="text" name="withdrawal_reason" class="form-control form-control-sm mb-1 @error('withdrawal_reason', 'entry_'.$entry->id) is-invalid @enderror" required placeholder="{{ __('vacancymaster.entries.withdrawal_reason') }}" @if ($entryFocus === 'withdrawal_reason') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'withdrawal_reason', 'bag' => 'entry_'.$entry->id])
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

    @if ($editable)
        <div class="card-body border-top">
@php
    $addOld = fn (string $key, $default = null) => old('_form') === 'add_entry' ? old($key, $default) : $default;
    $addFocus = collect(['kind', 'reason', 'vacancy_count', 'effective_date', 'occupant_quota', 'reconciliation', 'order_number', 'order_date', 'document', 'higher_post_id', 'higher_dpc_reference', 'promotion_order_number', 'promotion_order_date', 'deputation_to', 'deputation_expected_months', 'against_sanctioned_post', 'cadre_details', 'remarks'])->first(fn ($field) => $errors->has($field));
@endphp
            <h3 class="h6" id="add-entry">{{ __('vacancymaster.entries.add_heading') }}</h3>
            <form method="POST" enctype="multipart/form-data" action="{{ route($records.'.entries.store', [$department, $year, $post]) }}">
                @csrf
                <input type="hidden" name="_form" value="add_entry">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small" for="kind">{{ __('vacancymaster.entries.kind') }}</label>
                        <select class="form-select form-select-sm @error('kind') is-invalid @enderror" id="kind" name="kind" required @if ($addFocus === 'kind') autofocus @endif>
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}" @selected($addOld('kind') === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'kind'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="reason">{{ __('vacancymaster.entries.reason') }}</label>
                        <select class="form-select form-select-sm @error('reason') is-invalid @enderror" id="reason" name="reason" required @if ($addFocus === 'reason') autofocus @endif>
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->value }}" @selected($addOld('reason') === $reason->value)>{{ $reason->label() }}</option>
                            @endforeach
                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'reason'])
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="vacancy_count">{{ __('vacancymaster.entries.count') }}</label>
                        <input type="number" min="1" class="form-control form-control-sm @error('vacancy_count') is-invalid @enderror" id="vacancy_count" name="vacancy_count" value="{{ $addOld('vacancy_count') }}" required @if ($addFocus === 'vacancy_count') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'vacancy_count'])
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="effective_date">{{ __('vacancymaster.entries.date') }}</label>
                        <input type="date" class="form-control form-control-sm @error('effective_date') is-invalid @enderror" id="effective_date" name="effective_date" value="{{ $addOld('effective_date') }}" required @if ($addFocus === 'effective_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'effective_date'])
                        <div class="form-text">{{ __('vacancymaster.entries.date_help', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="occupant_quota">{{ __('vacancymaster.entries.quota') }}</label>
                        <select class="form-select form-select-sm @error('occupant_quota') is-invalid @enderror" id="occupant_quota" name="occupant_quota" @if ($addFocus === 'occupant_quota') autofocus @endif>
                            <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                            <option value="promotion" @selected($addOld('occupant_quota') === 'promotion')>{{ __('vacancymaster.quota.promotion') }}</option>
                            <option value="direct" @selected($addOld('occupant_quota') === 'direct')>{{ __('vacancymaster.quota.direct') }}</option>
                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'occupant_quota'])
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="reconciliation">{{ __('vacancymaster.entries.reconciliation') }}</label>
                        <select class="form-select form-select-sm @error('reconciliation') is-invalid @enderror" id="reconciliation" name="reconciliation" @if ($addFocus === 'reconciliation') autofocus @endif>
                            <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                            @foreach ($reconciliations as $reconciliation)
                                <option value="{{ $reconciliation->value }}" @selected($addOld('reconciliation') === $reconciliation->value)>{{ $reconciliation->label() }}</option>
                            @endforeach
                        </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'reconciliation'])
                        <div class="form-text">{{ __('vacancymaster.entries.reconciliation_help') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="entry_order_number">{{ __('vacancymaster.order_number') }}</label>
                        <input type="text" maxlength="120" class="form-control form-control-sm @error('order_number') is-invalid @enderror" id="entry_order_number" name="order_number" value="{{ $addOld('order_number') }}" required @if ($addFocus === 'order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_number'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="entry_order_date">{{ __('vacancymaster.order_date') }}</label>
                        <input type="date" class="form-control form-control-sm @error('order_date') is-invalid @enderror" id="entry_order_date" name="order_date" value="{{ $addOld('order_date') }}" required @if ($addFocus === 'order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_date'])
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="entry_document">{{ __('vacancymaster.document') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm @error('document') is-invalid @enderror" id="entry_document" name="document" required @if ($addFocus === 'document') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'document'])
@if ($errors->any() && ! $errors->has('document') && old('_form') === 'add_entry')
    <div class="form-text text-danger">{{ __('vacancymaster.errors.document_reselect') }}</div>
@endif
                    </div>

                    <details class="col-12 border rounded p-2" @if ($addOld('higher_post_id') || $addOld('higher_dpc_reference') || $addOld('promotion_order_number') || $errors->hasAny(['higher_post_id', 'higher_dpc_reference', 'promotion_order_number', 'promotion_order_date'])) open @endif>
                        <summary class="small fw-semibold">{{ __('vacancymaster.entries.higher_heading') }} — {{ __('vacancymaster.steps.more_details') }}</summary>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small" for="higher_post_id">{{ __('vacancymaster.entries.higher_post') }}</label>
                                <select class="form-select form-select-sm @error('higher_post_id') is-invalid @enderror" id="higher_post_id" name="higher_post_id" @if ($addFocus === 'higher_post_id') autofocus @endif>
                                    <option value="">{{ __('vacancymaster.entries.not_applicable') }}</option>
                                    @foreach ($higherPosts as $higher)
                                        <option value="{{ $higher->id }}" @selected((int) $addOld('higher_post_id') === $higher->id)>{{ $higher->name }}</option>
                                    @endforeach
                                </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'higher_post_id'])
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="higher_dpc_reference">{{ __('vacancymaster.entries.higher_dpc_reference') }}</label>
                                <input type="text" maxlength="60" class="form-control form-control-sm @error('higher_dpc_reference') is-invalid @enderror" id="higher_dpc_reference" name="higher_dpc_reference" value="{{ $addOld('higher_dpc_reference') }}" @if ($addFocus === 'higher_dpc_reference') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'higher_dpc_reference'])
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="promotion_order_number">{{ __('vacancymaster.entries.promotion_order_number') }}</label>
                                <input type="text" maxlength="120" class="form-control form-control-sm @error('promotion_order_number') is-invalid @enderror" id="promotion_order_number" name="promotion_order_number" value="{{ $addOld('promotion_order_number') }}" @if ($addFocus === 'promotion_order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'promotion_order_number'])
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small" for="promotion_order_date">{{ __('vacancymaster.entries.promotion_order_date') }}</label>
                                <input type="date" class="form-control form-control-sm @error('promotion_order_date') is-invalid @enderror" id="promotion_order_date" name="promotion_order_date" value="{{ $addOld('promotion_order_date') }}" @if ($addFocus === 'promotion_order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'promotion_order_date'])
                            </div>
                        </div>
                    </details>

                    <details class="col-12 border rounded p-2" @if ($addOld('deputation_to') || $addOld('deputation_expected_months') || $addOld('against_sanctioned_post') !== null || $addOld('cadre_details') || $errors->hasAny(['deputation_to', 'deputation_expected_months', 'against_sanctioned_post', 'cadre_details'])) open @endif>
                        <summary class="small fw-semibold">{{ __('vacancymaster.entries.deputation_heading') }} — {{ __('vacancymaster.steps.more_details') }}</summary>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label small" for="deputation_to">{{ __('vacancymaster.entries.deputation_to') }}</label>
                                <input type="date" class="form-control form-control-sm @error('deputation_to') is-invalid @enderror" id="deputation_to" name="deputation_to" value="{{ $addOld('deputation_to') }}" @if ($addFocus === 'deputation_to') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'deputation_to'])
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="deputation_expected_months">{{ __('vacancymaster.entries.deputation_months') }}</label>
                                <input type="number" min="1" max="120" class="form-control form-control-sm @error('deputation_expected_months') is-invalid @enderror" id="deputation_expected_months" name="deputation_expected_months" value="{{ $addOld('deputation_expected_months') }}" @if ($addFocus === 'deputation_expected_months') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'deputation_expected_months'])
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="against_sanctioned_post">{{ __('vacancymaster.entries.against_sanctioned_post') }}</label>
                                <select class="form-select form-select-sm @error('against_sanctioned_post') is-invalid @enderror" id="against_sanctioned_post" name="against_sanctioned_post" @if ($addFocus === 'against_sanctioned_post') autofocus @endif>
                                    <option value="">{{ __('vacancymaster.entries.not_stated') }}</option>
                                    <option value="1" @selected($addOld('against_sanctioned_post') === '1')>{{ __('vacancymaster.yes') }}</option>
                                    <option value="0" @selected($addOld('against_sanctioned_post') === '0')>{{ __('vacancymaster.no') }}</option>
                                </select>
@include('admin.vacancy-master.partials.field-error', ['field' => 'against_sanctioned_post'])
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="cadre_details">{{ __('vacancymaster.entries.cadre_details') }}</label>
                                <input type="text" maxlength="255" class="form-control form-control-sm @error('cadre_details') is-invalid @enderror" id="cadre_details" name="cadre_details" value="{{ $addOld('cadre_details') }}" @if ($addFocus === 'cadre_details') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'cadre_details'])
                            </div>
                        </div>
                    </details>

                    <div class="col-12">
                        <label class="form-label small" for="entry_remarks">{{ __('vacancymaster.remarks') }}</label>
                        <textarea class="form-control form-control-sm @error('remarks') is-invalid @enderror" id="entry_remarks" name="remarks" rows="2" maxlength="2000" @if ($addFocus === 'remarks') autofocus @endif>{{ $addOld('remarks') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'remarks'])
                    </div>
                    <div class="col-12"><button class="btn btn-primary btn-sm">{{ __('vacancymaster.entries.add') }}</button></div>
                </div>
            </form>
        </div>
    @endif
</div>
