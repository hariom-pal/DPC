{{-- Step 1 of a record kept per Pre-DPC (2026-10-31): sanctioned and working strength
     on one screen, with one sanctioning order. Officers of other departments here on
     deputation are named in step 2 (B1) and added to the working figure. --}}
@php
    $incoming = (int) ($b['working']['deputation_in'] ?? 0);
    $hasFigures = $sanctioned !== null && $working !== null;
@endphp
<div class="card border-0 shadow-sm mb-3" id="strength-step">
    <div class="card-header bg-white"><strong>1. {{ __('vacancymaster.pm.strength_heading', ['year' => $year]) }}</strong></div>
    <div class="card-body">
        @if ($sanctioned !== null || $working !== null)
            <div class="table-responsive mb-3">
                <table class="table table-sm small mb-0" id="working-figures" style="max-width:40rem">
                    <tbody>
                        <tr>
                            <td>{{ __('vacancymaster.sanctioned.total') }}</td>
                            <td class="text-end fw-semibold">{{ $sanctioned?->sanctioned_total ?? __('vacancymaster.not_entered') }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('vacancymaster.pm.working_own') }}</td>
                            <td class="text-end">{{ $working?->working_total ?? __('vacancymaster.not_entered') }}</td>
                        </tr>
                        <tr>
                            <td>+ {{ __('vacancymaster.pm.working_here') }}</td>
                            <td class="text-end">{{ $incoming }}</td>
                        </tr>
                        <tr class="table-light fw-semibold">
                            <td>{{ __('vacancymaster.pm.working_total') }}</td>
                            <td class="text-end">{{ $b['working']['total'] ?? '—' }}</td>
                        </tr>
                        @if ($sanctioned !== null)
                            <tr>
                                <td>{{ __('vacancymaster.pm.order_block') }}</td>
                                <td class="text-end">
                                    {{ $sanctioned->order_number }} · {{ $sanctioned->order_date->format('d-m-Y') }} ·
                                    <a href="{{ route($records.'.sanctioned-strength.document', [$department, $year, $post, $sanctioned]) }}">{{ $sanctioned->original_name }}</a>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endif

        @if ($editable)
@php
    $focus = collect(['sanctioned_total', 'working_total', 'order_number', 'order_date', 'document', 'remarks', 'correction_reason'])->first(fn ($field) => $errors->has($field));
@endphp
            @if ($hasFigures)
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#strength-edit"
                        aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="strength-edit">{{ __('vacancymaster.pm.change_figures') }}</button>
            @endif
            {{-- Once both figures are in, the form folds away until asked for (or refused). --}}
            <div @class(['collapse', 'show' => ! $hasFigures || $errors->any(), 'mt-3' => $hasFigures]) id="strength-edit">
            <p class="small text-muted mb-2">{{ __('vacancymaster.pm.strength_intro') }}</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route($records.'.strength', [$department, $year, $post]) }}" id="strength-form">
                @csrf
                <div class="row g-2">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small" for="sanctioned_total">{{ __('vacancymaster.sanctioned.total') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @error('sanctioned_total') is-invalid @enderror" id="sanctioned_total" name="sanctioned_total" value="{{ old('sanctioned_total', $sanctioned?->sanctioned_total) }}" required @if ($focus === 'sanctioned_total') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'sanctioned_total'])
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small" for="working_total">{{ __('vacancymaster.pm.working_own') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @error('working_total') is-invalid @enderror" id="working_total" name="working_total" value="{{ old('working_total', $working?->working_total) }}" required @if ($focus === 'working_total') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_total'])
                    </div>
                    <div class="col-lg-6"><div class="form-text mt-lg-4">{{ __('vacancymaster.pm.working_own_help') }}</div></div>
                </div>

                <fieldset class="border rounded-3 p-2 mt-3">
                    <legend class="float-none w-auto px-1 mb-0 small fw-semibold">{{ __('vacancymaster.pm.order_block') }}</legend>
                    @if ($sanctioned !== null)
                        <div class="small text-muted mb-1">{{ __('vacancymaster.pm.order_block_later') }}</div>
                    @endif
                    <div class="row g-2">
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label small" for="ss_order_number">{{ __('vacancymaster.order_number') }}</label>
                            <input type="text" maxlength="120" class="form-control form-control-sm @error('order_number') is-invalid @enderror" id="ss_order_number" name="order_number" value="{{ old('order_number') }}" @if ($sanctioned === null) required @endif @if ($focus === 'order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_number'])
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label small" for="ss_order_date">{{ __('vacancymaster.order_date') }}</label>
                            <input type="date" class="form-control form-control-sm @error('order_date') is-invalid @enderror" id="ss_order_date" name="order_date" value="{{ old('order_date') }}" @if ($sanctioned === null) required @endif @if ($focus === 'order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_date'])
                        </div>
                        <div class="col-lg-5">
                            <label class="form-label small" for="ss_document">{{ __('vacancymaster.document') }}</label>
                            <input type="file" accept="application/pdf" class="form-control form-control-sm @error('document') is-invalid @enderror" id="ss_document" name="document" @if ($sanctioned === null) required @endif @if ($focus === 'document') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'document'])
@if ($errors->any() && ! $errors->has('document') && old('order_number'))
    <div class="form-text text-danger">{{ __('vacancymaster.errors.document_reselect') }}</div>
@endif
                        </div>
                    </div>
                </fieldset>

                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label small" for="ss_remarks">{{ __('vacancymaster.remarks') }}</label>
                        <textarea class="form-control form-control-sm @error('remarks') is-invalid @enderror" id="ss_remarks" name="remarks" rows="2" maxlength="2000" @if ($focus === 'remarks') autofocus @endif>{{ old('remarks') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'remarks'])
                    </div>
                    @if ($sanctioned !== null || $working !== null)
                        <div class="col-md-6">
                            <label class="form-label small" for="ss_correction_reason">{{ __('vacancymaster.sanctioned.correction_reason') }}</label>
                            <textarea class="form-control form-control-sm @error('correction_reason') is-invalid @enderror" id="ss_correction_reason" name="correction_reason" rows="2" maxlength="2000" @if ($focus === 'correction_reason') autofocus @endif>{{ old('correction_reason') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'correction_reason'])
                        </div>
                    @endif
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm">{{ $hasFigures ? __('vacancymaster.pm.strength_correct') : __('vacancymaster.pm.strength_save') }}</button>
                    </div>
                </div>
            </form>
            </div>
        @endif

        @if ($position->sanctionedStrengthHistory->count() > 1 || $position->workingStrengthHistory->count() > 1)
            <details class="mt-3">
                <summary class="small fw-semibold">{{ __('vacancymaster.pm.history') }}</summary>
                <div class="table-responsive mt-2">
                    <table class="table table-sm small mb-0">
                        <thead><tr>
                            <th>{{ __('vacancymaster.pm.figure') }}</th><th>{{ __('vacancymaster.order') }}</th>
                            <th>{{ __('vacancymaster.sanctioned.correction_reason') }}</th><th>{{ __('vacancymaster.by') }}</th><th>{{ __('vacancymaster.at') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($position->sanctionedStrengthHistory->map(fn ($row) => ['label' => __('vacancymaster.sanctioned.total'), 'value' => $row->sanctioned_total, 'row' => $row, 'route' => 'sanctioned-strength.document'])
                                ->concat($position->workingStrengthHistory->map(fn ($row) => ['label' => __('vacancymaster.pm.working_own'), 'value' => $row->working_total, 'row' => $row, 'route' => 'working-strength.document']))
                                ->sortByDesc(fn ($item) => $item['row']->recorded_at) as $item)
                                <tr>
                                    <td>{{ $item['label'] }}: <strong>{{ $item['value'] }}</strong></td>
                                    <td>
                                        {{ $item['row']->order_number ?? '—' }}
                                        @if ($item['row']->original_name)
                                            · <a href="{{ route($records.'.'.$item['route'], [$department, $year, $post, $item['row']]) }}">{{ $item['row']->original_name }}</a>
                                        @endif
                                    </td>
                                    <td>{{ $item['row']->correction_reason ?? '—' }}</td>
                                    <td>{{ $item['row']->recorder?->name ?? '—' }}</td>
                                    <td>{{ $item['row']->recorded_at->format('d-m-Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </div>
</div>
