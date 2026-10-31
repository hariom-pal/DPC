{{-- Step 2. Working strength as on 1 January: one figure, our own officers on the post
     (those on deputation elsewhere included). Officers of other departments working
     here on deputation are named in step 3 (B1) and added to it. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>2. {{ __('vacancymaster.working.heading', ['date' => '01-01-'.$year]) }}</strong></div>
    <div class="card-body">
        <div @class(['alert small py-2', 'alert-light border' => $sanctioned !== null, 'alert-warning' => $sanctioned === null])>
            @if ($sanctioned !== null)
                {{ __('vacancymaster.sanctioned.reference', ['year' => $year, 'count' => $sanctioned->sanctioned_total]) }}
            @else
                {{ __('vacancymaster.sanctioned.reference_missing', ['year' => $year]) }}
            @endif
        </div>

        <table class="table table-sm small mb-3" id="working-figures" style="max-width:36rem">
            <tbody>
                <tr><td>{{ __('vacancymaster.pm.working_own') }}</td><td class="text-end">{{ $working?->working_total ?? __('vacancymaster.not_entered') }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.working_incoming') }}</td><td class="text-end">{{ $b['working']['deputation_in'] ?? 0 }}</td></tr>
                <tr class="table-light fw-semibold"><td>{{ __('vacancymaster.pm.working_total') }}</td><td class="text-end">{{ $b['working']['total'] ?? '—' }}</td></tr>
            </tbody>
        </table>

        @if ($working !== null && ($working->order_number || $working->original_name || $working->remarks))
            <dl class="row small mb-3">
                @if ($working->order_number || $working->original_name)
                    <dt class="col-sm-4">{{ __('vacancymaster.order') }}</dt>
                    <dd class="col-sm-8">
                        {{ $working->order_number ?? '—' }} · {{ $working->order_date?->format('d-m-Y') ?? '—' }}
                        @if ($working->original_name)
                            · <a href="{{ route($records.'.working-strength.document', [$department, $year, $post, $working]) }}">{{ $working->original_name }}</a>
                        @endif
                    </dd>
                @endif
                @if ($working->remarks)
                    <dt class="col-sm-4">{{ __('vacancymaster.remarks') }}</dt><dd class="col-sm-8">{{ $working->remarks }}</dd>
                @endif
            </dl>
        @endif

        @if ($editable)
@php
    $focus = collect(['working_total', 'order_number', 'order_date', 'document', 'remarks', 'correction_reason'])->first(fn ($field) => $errors->has($field));
@endphp
            <form method="POST" enctype="multipart/form-data" action="{{ route($records.'.working-strength', [$department, $year, $post]) }}" id="working-form">
                @csrf
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small" for="working_total">{{ __('vacancymaster.pm.working_own') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @error('working_total') is-invalid @enderror" id="working_total" name="working_total" value="{{ old('working_total', $working?->working_total) }}" required @if ($focus === 'working_total') autofocus @endif>
                        <div class="form-text">{{ __('vacancymaster.pm.working_own_help') }}</div>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_total'])
                    </div>
                    <div class="col-12"><div class="small text-muted">{{ __('vacancymaster.pm.optional_order') }}</div></div>
                    <div class="col-md-4">
                        <label class="form-label small" for="order_number">{{ __('vacancymaster.order_number') }}</label>
                        <input type="text" maxlength="120" class="form-control form-control-sm @error('order_number') is-invalid @enderror" id="order_number" name="order_number" value="{{ old('order_number') }}" @if ($focus === 'order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_number'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="order_date">{{ __('vacancymaster.order_date') }}</label>
                        <input type="date" class="form-control form-control-sm @error('order_date') is-invalid @enderror" id="order_date" name="order_date" value="{{ old('order_date') }}" @if ($focus === 'order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_date'])
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="ws_document">{{ __('vacancymaster.document') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm @error('document') is-invalid @enderror" id="ws_document" name="document" @if ($focus === 'document') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'document'])
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="ws_remarks">{{ __('vacancymaster.remarks') }}</label>
                        <textarea class="form-control form-control-sm @error('remarks') is-invalid @enderror" id="ws_remarks" name="remarks" rows="2" maxlength="2000" @if ($focus === 'remarks') autofocus @endif>{{ old('remarks') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'remarks'])
                    </div>
                    @if ($working !== null)
                        <div class="col-md-6">
                            <label class="form-label small" for="correction_reason">{{ __('vacancymaster.working.correction_reason') }}</label>
                            <textarea class="form-control form-control-sm @error('correction_reason') is-invalid @enderror" id="correction_reason" name="correction_reason" rows="2" maxlength="2000" required @if ($focus === 'correction_reason') autofocus @endif>{{ old('correction_reason') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'correction_reason'])
                        </div>
                    @endif
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
                        <th>{{ __('vacancymaster.pm.working_own') }}</th>
                        <th>{{ __('vacancymaster.order') }}</th><th>{{ __('vacancymaster.working.correction_reason') }}</th>
                        <th>{{ __('vacancymaster.by') }}</th><th>{{ __('vacancymaster.at') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($position->workingStrengthHistory as $row)
                            <tr>
                                <td>{{ $row->working_total }}</td>
                                <td>
                                    {{ $row->order_number ?? '—' }}
                                    @if ($row->original_name)
                                        · <a href="{{ route($records.'.working-strength.document', [$department, $year, $post, $row]) }}">{{ $row->original_name }}</a>
                                    @endif
                                </td>
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
