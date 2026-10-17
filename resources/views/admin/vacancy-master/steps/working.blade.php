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
