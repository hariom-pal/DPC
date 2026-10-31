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
@endphp

{{-- The running total, so the officer sees each row land. --}}
<div class="card border-0 shadow-sm mb-3" id="vacancy-tally">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 small">
            <span>{{ __('vacancymaster.pm.a_clear') }}: <strong>{{ $b['clear_vacancy'] ?? '—' }}</strong></span>
            <span>{{ __('vacancymaster.pm.b1_short') }}: <strong>{{ $b['deputation_in_vacancy'] ?? 0 }}</strong></span>
            <span>{{ __('vacancymaster.pm.b2_short') }}: <strong>{{ $b['deputation_out_vacancy'] ?? 0 }}</strong></span>
            <span>{{ __('vacancymaster.pm.c_short') }}: <strong>{{ $b['anticipated_vacancy'] ?? 0 }}</strong></span>
            <span>{{ __('vacancymaster.pm.total') }}: <strong>{{ $b['total_vacancy'] ?? '—' }}</strong> / {{ __('vacancymaster.pm.sanctioned_short') }} {{ $b['sanctioned'] ?? '—' }}</span>
        </div>
        @if ($editable && ($sanctioned === null || $working === null))
            <div class="alert alert-warning small py-2 mt-2 mb-0">{{ __('vacancymaster.errors.figures_first') }}</div>
        @endif
        @if ($mine('') || ($errors->has('workflow') && old('kind') === null))
            @include('admin.vacancy-master.partials.field-error', ['field' => 'workflow'])
        @endif
    </div>
</div>

@foreach ([
    'deputation_in' => ['id' => 'b1', 'title' => 'b1_heading', 'help' => 'b1_help'],
    'deputation' => ['id' => 'b2', 'title' => 'b2_heading', 'help' => 'b2_help'],
    'anticipated' => ['id' => 'c', 'title' => 'c_heading', 'help' => 'c_help'],
] as $kind => $card)
    @php $rows = $rowsOf($kind); @endphp
    <div class="card border-0 shadow-sm mb-3" id="vacancy-{{ $card['id'] }}">
        <div class="card-header bg-white"><strong>{{ __('vacancymaster.pm.'.$card['title']) }}</strong></div>
        <div class="card-body pb-0"><p class="small text-muted mb-2">{{ __('vacancymaster.pm.'.$card['help'], ['year' => $year, 'date' => '31-12-'.$year]) }}</p></div>
        <div class="table-responsive">
            <table class="table table-sm small mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('vacancymaster.entries.officer_name') }}</th>
                    <th>{{ __('vacancymaster.pm.details') }}</th>
                    <th>{{ __('vacancymaster.order') }}</th>
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
                                @if ($entry->remarks)<div class="text-muted">{{ $entry->remarks }}</div>@endif
                            </td>
                            <td>
                                {{ $entry->order_number ?? '—' }}
                                @if ($entry->order_date) · {{ $entry->order_date->format('d-m-Y') }}@endif
                                @if ($entry->original_name)
                                    <div><a href="{{ route($records.'.entries.document', [$department, $year, $post, $entry]) }}">{{ $entry->original_name }}</a></div>
                                @endif
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
                            <td class="text-end" style="min-width:12rem">
                                @if ($editable && $entry->isActive())
@php $entryBag = $errors->getBag('entry_'.$entry->id); @endphp
                                    <form method="POST" class="text-start" action="{{ route($records.'.entries.withdraw', [$department, $year, $post, $entry]) }}">
                                        @csrf
                                        <input type="hidden" name="_entry" value="{{ $entry->id }}">
@include('admin.vacancy-master.partials.field-error', ['field' => 'workflow', 'bag' => 'entry_'.$entry->id])
                                        <input type="text" name="withdrawal_reason" class="form-control form-control-sm mb-1 @if ($entryBag->has('withdrawal_reason')) is-invalid @endif" required placeholder="{{ __('vacancymaster.entries.withdrawal_reason') }}">
@include('admin.vacancy-master.partials.field-error', ['field' => 'withdrawal_reason', 'bag' => 'entry_'.$entry->id])
                                        <button class="btn btn-outline-danger btn-sm">{{ __('vacancymaster.pm.remove') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-2">{{ __('vacancymaster.pm.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($editable && $sanctioned !== null && $working !== null)
            <div class="card-body border-top">
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
                        <div class="col-md-3">
                            <button class="btn btn-primary btn-sm">{{ __('vacancymaster.pm.add_row') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endforeach

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

