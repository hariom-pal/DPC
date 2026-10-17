{{-- Step 2. Working strength as on 1 January. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>2. {{ __('vacancymaster.working.heading', ['date' => '01-01-'.$year]) }}</strong></div>
    <div class="card-body">
        <p class="small text-muted">{{ __('vacancymaster.steps.working_intro', ['year' => $year]) }}</p>
        <div @class(['alert small py-2', 'alert-light border' => $sanctioned !== null, 'alert-warning' => $sanctioned === null])>
            @if ($sanctioned !== null)
                {{ __('vacancymaster.sanctioned.reference', ['year' => $year, 'count' => $sanctioned->sanctioned_total]) }}
            @else
                {{ __('vacancymaster.sanctioned.reference_missing', ['year' => $year]) }}
            @endif
        </div>
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
@php
    $focus = collect(['working_total', 'working_promotion', 'working_direct', 'working_deputation', 'order_number', 'order_date', 'document', 'remarks', 'correction_reason', 'exceeds_sanctioned_acknowledged'])->first(fn ($field) => $errors->has($field)) ?? ($errors->has('working_split') ? 'working_promotion' : null);
@endphp
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.departments.vacancy-master.working-strength', [$department, $year, $post]) }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small" for="working_total">{{ __('vacancymaster.working.total') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @error('working_total') is-invalid @enderror" id="working_total" name="working_total" value="{{ old('working_total', $working?->working_total) }}" required @if ($focus === 'working_total') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_total'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="working_promotion">{{ __('vacancymaster.working.promotion') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @if ($errors->hasAny(['working_promotion', 'working_split'])) is-invalid @endif" id="working_promotion" name="working_promotion" value="{{ old('working_promotion', $working?->working_promotion) }}" @if ($focus === 'working_promotion') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_promotion'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="working_direct">{{ __('vacancymaster.working.direct') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @if ($errors->hasAny(['working_direct', 'working_split'])) is-invalid @endif" id="working_direct" name="working_direct" value="{{ old('working_direct', $working?->working_direct) }}" @if ($focus === 'working_direct') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_direct'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="working_deputation">{{ __('vacancymaster.working.deputation') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm @if ($errors->hasAny(['working_deputation', 'working_split'])) is-invalid @endif" id="working_deputation" name="working_deputation" value="{{ old('working_deputation', $working?->working_deputation) }}" @if ($focus === 'working_deputation') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_deputation'])
                    </div>
                    <div class="col-12"><div class="form-text">{{ __('vacancymaster.working.split_help') }}</div>
@include('admin.vacancy-master.partials.field-error', ['field' => 'working_split'])</div>
                    <div class="col-md-4">
                        <label class="form-label small" for="order_number">{{ __('vacancymaster.order_number') }}</label>
                        <input type="text" maxlength="120" class="form-control form-control-sm @error('order_number') is-invalid @enderror" id="order_number" name="order_number" value="{{ old('order_number') }}" required @if ($focus === 'order_number') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_number'])
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="order_date">{{ __('vacancymaster.order_date') }}</label>
                        <input type="date" class="form-control form-control-sm @error('order_date') is-invalid @enderror" id="order_date" name="order_date" value="{{ old('order_date') }}" required @if ($focus === 'order_date') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'order_date'])
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="ws_document">{{ __('vacancymaster.document') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm @error('document') is-invalid @enderror" id="ws_document" name="document" required @if ($focus === 'document') autofocus @endif>
@include('admin.vacancy-master.partials.field-error', ['field' => 'document'])
@if ($errors->any() && ! $errors->has('document'))
    <div class="form-text text-danger">{{ __('vacancymaster.errors.document_reselect') }}</div>
@endif
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
                        <div class="form-check">
                            <input class="form-check-input @error('exceeds_sanctioned_acknowledged') is-invalid @enderror" type="checkbox" value="1" id="exceeds_sanctioned_acknowledged" name="exceeds_sanctioned_acknowledged" @if ($focus === 'exceeds_sanctioned_acknowledged') autofocus @endif>
                            <label class="form-check-label small" for="exceeds_sanctioned_acknowledged">{{ __('vacancymaster.working.exceeds_ack') }}</label>
@include('admin.vacancy-master.partials.field-error', ['field' => 'exceeds_sanctioned_acknowledged'])
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
