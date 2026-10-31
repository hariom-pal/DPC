{{-- Step 3. The rows of the record (2026-10-30): B1 officers here on deputation,
     B2 our officers on deputation elsewhere, C retirements and promotions to a higher
     post during the year. One row is one officer. Each form posts its own `kind`, so
     a refused save shows its messages beside that form only. --}}
@php
    $active = $position->entries->filter(fn ($entry) => $entry->isActive());
    $rowsOf = fn (string $kind) => $position->entries->filter(fn ($entry) => $entry->kind->value === $kind);
    $older = $position->entries->filter(fn ($entry) => $entry->kind->value === 'existing');
    $mine = fn (string $kind): bool => old('kind') === $kind;
    $err = fn (string $kind, string $field) => $mine($kind) && $errors->has($field);
    $val = fn (string $kind, string $field, $default = null) => $mine($kind) ? old($field, $default) : $default;
    $yearFrom = $year.'-01-01';
    $yearTo = $year.'-12-31';

    // One part at a time (2026-10-31). Open: the part whose form or row was just
    // refused, else the part asked for (the redirect after a save names it), else B1.
    $partOf = ['deputation_in' => 'b1', 'deputation' => 'b2', 'anticipated' => 'c'];
    $refusedRow = $position->entries->first(fn ($entry) => $errors->getBag('entry_'.$entry->id)->any());
    $part = $partOf[(string) old('kind')]
        ?? ($refusedRow !== null ? ($partOf[$refusedRow->kind->value] ?? null) : null)
        ?? (in_array(request('part'), $partOf, true) ? request('part') : 'b1');
@endphp

{{-- The running total, so the officer sees each row land: A + B1 + B2 + C = total. --}}
<div class="card border-0 shadow-sm mb-3" id="vacancy-tally">
    <div class="card-body py-2">
        <div class="vm-tally">
            @foreach ([
                ['a_clear', $b['clear_vacancy'] ?? '—'],
                ['b1_short', $b['deputation_in_vacancy'] ?? 0],
                ['b2_short', $b['deputation_out_vacancy'] ?? 0],
                ['c_short', $b['anticipated_vacancy'] ?? 0],
            ] as $i => [$label, $figure])
                @if ($i > 0)<span class="vm-op" aria-hidden="true">+</span>@endif
                <div class="vm-tile"><div class="vm-tile-value">{{ $figure }}</div><div class="vm-tile-label">{{ __('vacancymaster.pm.'.$label) }}</div></div>
            @endforeach
            <span class="vm-op" aria-hidden="true">=</span>
            <div class="vm-tile vm-tile-total">
                <div class="vm-tile-value">{{ $b['total_vacancy'] ?? '—' }} <span class="vm-tile-of">/ {{ $b['sanctioned'] ?? '—' }}</span></div>
                <div class="vm-tile-label">{{ __('vacancymaster.pm.total') }} / {{ __('vacancymaster.pm.sanctioned_short') }}</div>
            </div>
        </div>
        @if ($editable && ($sanctioned === null || $working === null))
            <div class="alert alert-warning small py-2 mt-2 mb-0">{{ __('vacancymaster.errors.figures_first') }}</div>
        @endif
        @if ($mine('') || ($errors->has('workflow') && old('kind') === null))
            @include('admin.vacancy-master.partials.field-error', ['field' => 'workflow'])
        @endif
    </div>
</div>

@php
    $cards = [
        'deputation_in' => ['id' => 'b1', 'title' => 'b1_heading', 'help' => 'b1_help'],
        'deputation' => ['id' => 'b2', 'title' => 'b2_heading', 'help' => 'b2_help'],
        'anticipated' => ['id' => 'c', 'title' => 'c_heading', 'help' => 'c_help'],
    ];
@endphp
<ul class="nav nav-tabs vm-parts" role="tablist" aria-label="{{ __('vacancymaster.pm.parts_label') }}">
    @foreach ($cards as $kind => $card)
        @php $tabRows = $rowsOf($kind)->filter(fn ($entry) => $entry->isActive()); @endphp
        <li class="nav-item" role="presentation">
            <button type="button" role="tab" id="tab-{{ $card['id'] }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $card['id'] }}"
                    aria-controls="pane-{{ $card['id'] }}" aria-selected="{{ $part === $card['id'] ? 'true' : 'false' }}"
                    @class(['nav-link', 'active' => $part === $card['id']])>
                {{ __('vacancymaster.pm.tab_'.$card['id']) }}
                <span class="badge rounded-pill text-bg-light border ms-1">{{ $tabRows->filter(fn ($entry) => $entry->isCounted())->count() }}/{{ $tabRows->count() }}</span>
            </button>
        </li>
    @endforeach
</ul>
<div class="tab-content mb-3">
@foreach ($cards as $kind => $card)
    @php
        $rows = $rowsOf($kind);
        $canAdd = $editable && $sanctioned !== null && $working !== null;
        $activeRows = $rows->filter(fn ($entry) => $entry->isActive());
    @endphp
    <div @class(['tab-pane', 'show active' => $part === $card['id']]) id="pane-{{ $card['id'] }}" role="tabpanel" aria-labelledby="tab-{{ $card['id'] }}" tabindex="0">
    <div class="card border-0 shadow-sm vm-part-card" id="vacancy-{{ $card['id'] }}">
        <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
            <strong class="me-auto">{{ __('vacancymaster.pm.'.$card['title']) }}</strong>
            @if ($activeRows->isNotEmpty())
                <span class="badge text-bg-light border">{{ __('vacancymaster.pm.rows_counted', ['counted' => $activeRows->filter(fn ($entry) => $entry->isCounted())->count(), 'rows' => $activeRows->count()]) }}</span>
            @endif
            @if ($canAdd)
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#add-{{ $card['id'] }}-panel"
                        aria-expanded="{{ $mine($kind) ? 'true' : 'false' }}" aria-controls="add-{{ $card['id'] }}-panel">+ {{ __('vacancymaster.pm.add_officer') }}</button>
            @endif
        </div>
        <p class="small text-muted px-3 pt-2 mb-2">{{ __('vacancymaster.pm.'.$card['help'], ['year' => $year, 'date' => '31-12-'.$year]) }}</p>
        <div class="table-responsive">
            <table class="table table-sm small mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('vacancymaster.entries.officer_name') }}</th>
                    <th>{{ __('vacancymaster.pm.details') }}</th>
                    <th>{{ __('vacancymaster.pm.counts') }}</th>
                    <th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $entry)
                        <tr @class(['text-muted' => ! $entry->isActive()]) data-entry-kind="{{ $kind }}">
                            <td>{{ $entry->officer_name ?? '—' }}</td>
                            <td>
                                @if ($kind === 'deputation_in')
                                    {{ __('vacancymaster.pm.came_ends', ['from' => $entry->deputation_from?->format('d-m-Y') ?? '—', 'to' => $entry->deputation_to?->format('d-m-Y') ?? '—']) }}
                                @elseif ($kind === 'deputation')
                                    {{ __('vacancymaster.pm.went_on', ['from' => $entry->deputation_from?->format('d-m-Y') ?? $entry->effective_date->format('d-m-Y')]) }}
                                    <div>{{ __('vacancymaster.entries.promotion_made') }}
                                        <strong>{{ $entry->promotion_made === null ? '—' : ($entry->promotion_made ? __('vacancymaster.yes') : __('vacancymaster.no')) }}</strong></div>
                                @else
                                    {{ $entry->reason->label() }} · {{ $entry->effective_date->format('d-m-Y') }}
                                    @if ($entry->higherPost)
                                        <div>{{ __('vacancymaster.entries.higher_summary', ['post' => $entry->higherPost->name, 'reference' => $entry->higher_dpc_reference]) }}</div>
                                    @endif
                                @endif
                                @if ($entry->order_number || $entry->original_name)
                                    <div class="text-muted">
                                        {{ __('vacancymaster.pm.order_line') }}: {{ $entry->order_number ?? '—' }}@if ($entry->order_date) · {{ $entry->order_date->format('d-m-Y') }}@endif
                                        @if ($entry->original_name)
                                            · <a href="{{ route($records.'.entries.document', [$department, $year, $post, $entry]) }}">{{ $entry->original_name }}</a>
                                        @endif
                                    </div>
                                @endif
                                @if ($entry->remarks)<div class="text-muted">{{ $entry->remarks }}</div>@endif
                            </td>
                            <td>
                                @if (! $entry->isActive())
                                    <span class="badge text-bg-secondary">{{ __('vacancymaster.entries.withdrawn') }}</span>
                                    <div>{{ $entry->withdrawal_reason }}</div>
                                @else
                                    <span @class(['badge', 'text-bg-success' => $entry->isCounted(), 'text-bg-light border' => ! $entry->isCounted()])>
                                        {{ $entry->isCounted() ? __('vacancymaster.pm.counted_plus_one') : __('vacancymaster.pm.not_counted') }}
                                    </span>
                                    @if ($entry->eligibility_note)<div class="text-muted">{{ $entry->eligibility_note }}</div>@endif
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($editable && $entry->isActive())
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#withdraw-{{ $entry->id }}"
                                            aria-expanded="false" aria-controls="withdraw-{{ $entry->id }}">{{ __('vacancymaster.pm.remove') }}</button>
                                @endif
                            </td>
                        </tr>
                        @if ($editable && $entry->isActive())
@php $entryBag = $errors->getBag('entry_'.$entry->id); @endphp
                            <tr @class(['collapse vm-withdraw', 'show' => $entryBag->any()]) id="withdraw-{{ $entry->id }}">
                                <td colspan="4">
                                    <form method="POST" action="{{ route($records.'.entries.withdraw', [$department, $year, $post, $entry]) }}">
                                        @csrf
                                        <input type="hidden" name="_entry" value="{{ $entry->id }}">
@include('admin.vacancy-master.partials.field-error', ['field' => 'workflow', 'bag' => 'entry_'.$entry->id])
                                        <div class="d-flex flex-wrap align-items-start gap-2">
                                            <div class="flex-grow-1" style="min-width:14rem">
                                                <label class="visually-hidden" for="withdrawal-reason-{{ $entry->id }}">{{ __('vacancymaster.entries.withdrawal_reason') }}</label>
                                                <input type="text" id="withdrawal-reason-{{ $entry->id }}" class="form-control form-control-sm @if ($entryBag->has('withdrawal_reason')) is-invalid @endif" name="withdrawal_reason" required placeholder="{{ __('vacancymaster.entries.withdrawal_reason') }}">
@include('admin.vacancy-master.partials.field-error', ['field' => 'withdrawal_reason', 'bag' => 'entry_'.$entry->id])
                                            </div>
                                            <button type="submit" class="btn btn-danger btn-sm">{{ __('vacancymaster.pm.remove_confirm') }}</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#withdraw-{{ $entry->id }}">{{ __('vacancymaster.pm.cancel') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-2">{{ __('vacancymaster.pm.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($canAdd)
            <div @class(['collapse border-top', 'show' => $mine($kind)]) id="add-{{ $card['id'] }}-panel"><div class="card-body">
                <form method="POST" enctype="multipart/form-data" action="{{ route($records.'.entries.store', [$department, $year, $post]) }}" id="add-{{ $card['id'] }}">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    @if ($mine($kind))
                        @include('admin.vacancy-master.partials.field-error', ['field' => 'workflow'])
                        @include('admin.vacancy-master.partials.field-error', ['field' => 'kind'])
                    @endif
                    <div class="row g-2 align-items-end">
                        @if ($kind === 'anticipated')
                            <div class="col-md-3">
                                <label class="form-label small" for="c_reason">{{ __('vacancymaster.entries.reason') }}</label>
                                <select class="form-select form-select-sm @if ($err($kind, 'reason')) is-invalid @endif" id="c_reason" name="reason" required data-higher-toggle>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->value }}" @selected($val($kind, 'reason') === $reason->value)>{{ $reason->label() }}</option>
                                    @endforeach
                                </select>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'reason']) @endif
                            </div>
                        @endif
                        <div class="col-md-3">
                            <label class="form-label small" for="{{ $card['id'] }}_name">{{ __('vacancymaster.entries.officer_name') }}</label>
                            <input type="text" maxlength="255" class="form-control form-control-sm @if ($err($kind, 'officer_name')) is-invalid @endif" id="{{ $card['id'] }}_name" name="officer_name" value="{{ $val($kind, 'officer_name') }}" required>
                            @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'officer_name']) @endif
                        </div>
                        @if ($kind === 'deputation_in')
                            <div class="col-md-3">
                                <label class="form-label small" for="b1_from">{{ __('vacancymaster.pm.came_on') }}</label>
                                <input type="date" class="form-control form-control-sm @if ($err($kind, 'deputation_from')) is-invalid @endif" id="b1_from" name="deputation_from" value="{{ $val($kind, 'deputation_from') }}" required>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'deputation_from']) @endif
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="b1_to">{{ __('vacancymaster.pm.ends_on') }}</label>
                                <input type="date" min="{{ $yearFrom }}" class="form-control form-control-sm @if ($err($kind, 'deputation_to')) is-invalid @endif" id="b1_to" name="deputation_to" value="{{ $val($kind, 'deputation_to') }}" required>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'deputation_to']) @endif
                            </div>
                        @elseif ($kind === 'deputation')
                            <div class="col-md-3">
                                <label class="form-label small" for="b2_from">{{ __('vacancymaster.pm.went_date') }}</label>
                                <input type="date" max="{{ $yearTo }}" class="form-control form-control-sm @if ($err($kind, 'deputation_from')) is-invalid @endif" id="b2_from" name="deputation_from" value="{{ $val($kind, 'deputation_from') }}" required>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'deputation_from']) @endif
                            </div>
                            <div class="col-md-6">
                                <fieldset>
                                    <legend class="form-label small mb-1">{{ __('vacancymaster.pm.declaration_promotion') }}</legend>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="promotion_made" id="b2_pm_no" value="0" @checked($val($kind, 'promotion_made') === '0') required>
                                        <label class="form-check-label small" for="b2_pm_no">{{ __('vacancymaster.pm.promotion_not_made') }}</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="promotion_made" id="b2_pm_yes" value="1" @checked($val($kind, 'promotion_made') === '1')>
                                        <label class="form-check-label small" for="b2_pm_yes">{{ __('vacancymaster.pm.promotion_made') }}</label>
                                    </div>
                                </fieldset>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'promotion_made']) @endif
                            </div>
                        @else
                            <div class="col-md-3">
                                <label class="form-label small" for="c_date">{{ __('vacancymaster.pm.date_in_year') }}</label>
                                <input type="date" min="{{ $yearFrom }}" max="{{ $yearTo }}" class="form-control form-control-sm @if ($err($kind, 'effective_date')) is-invalid @endif" id="c_date" name="effective_date" value="{{ $val($kind, 'effective_date') }}" required>
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'effective_date']) @endif
                            </div>
                            <div class="col-12 small text-muted">{{ __('vacancymaster.pm.order_optional_retirement') }}</div>
                            <div class="col-md-3">
                                <label class="form-label small" for="c_order_number">{{ __('vacancymaster.order_number') }}</label>
                                <input type="text" maxlength="120" class="form-control form-control-sm @if ($err($kind, 'order_number')) is-invalid @endif" id="c_order_number" name="order_number" value="{{ $val($kind, 'order_number') }}">
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'order_number']) @endif
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="c_order_date">{{ __('vacancymaster.order_date') }}</label>
                                <input type="date" class="form-control form-control-sm @if ($err($kind, 'order_date')) is-invalid @endif" id="c_order_date" name="order_date" value="{{ $val($kind, 'order_date') }}">
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'order_date']) @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" for="c_document">{{ __('vacancymaster.document') }}</label>
                                <input type="file" accept="application/pdf" class="form-control form-control-sm @if ($err($kind, 'document')) is-invalid @endif" id="c_document" name="document">
                                @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'document']) @endif
                            </div>
                            <div class="col-12" data-higher-fields>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small" for="higher_post_id">{{ __('vacancymaster.entries.higher_post') }}</label>
                                        <select class="form-select form-select-sm @if ($err($kind, 'higher_post_id')) is-invalid @endif" id="higher_post_id" name="higher_post_id">
                                            <option value="">—</option>
                                            @foreach ($higherPosts as $higher)
                                                <option value="{{ $higher->id }}" @selected((string) $val($kind, 'higher_post_id') === (string) $higher->id)>{{ $higher->name }}</option>
                                            @endforeach
                                        </select>
                                        @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'higher_post_id']) @endif
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small" for="higher_dpc_reference">{{ __('vacancymaster.entries.higher_dpc_reference') }}</label>
                                        <input type="text" maxlength="60" class="form-control form-control-sm @if ($err($kind, 'higher_dpc_reference')) is-invalid @endif" id="higher_dpc_reference" name="higher_dpc_reference" value="{{ $val($kind, 'higher_dpc_reference') }}">
                                        @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'higher_dpc_reference']) @endif
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input @if ($err($kind, 'higher_post_undertaking')) is-invalid @endif" type="checkbox" value="1" id="higher_post_undertaking" name="higher_post_undertaking" @checked($val($kind, 'higher_post_undertaking'))>
                                            <label class="form-check-label small" for="higher_post_undertaking">{{ __('vacancymaster.entries.higher_undertaking') }}</label>
                                        </div>
                                        @if ($mine($kind)) @include('admin.vacancy-master.partials.field-error', ['field' => 'higher_post_undertaking']) @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="col-md-6">
                            <label class="form-label small" for="{{ $card['id'] }}_remarks">{{ __('vacancymaster.remarks') }}</label>
                            <input type="text" maxlength="2000" class="form-control form-control-sm" id="{{ $card['id'] }}_remarks" name="remarks" value="{{ $val($kind, 'remarks') }}">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('vacancymaster.pm.add_row') }}</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#add-{{ $card['id'] }}-panel">{{ __('vacancymaster.pm.cancel') }}</button>
                        </div>
                    </div>
                </form>
            </div></div>
        @endif
    </div>
    </div>
@endforeach
</div>

@if ($older->isNotEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('vacancymaster.pm.older_rows') }}</strong></div>
        <ul class="list-group list-group-flush small">
            @foreach ($older as $entry)
                <li class="list-group-item">{{ $entry->kind->label() }} · {{ $entry->reason->label() }} · {{ $entry->vacancy_count }} · {{ $entry->effective_date->format('d-m-Y') }}</li>
            @endforeach
        </ul>
    </div>
@endif

