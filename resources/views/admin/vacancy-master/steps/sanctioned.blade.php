{{-- Step 1. Sanctioned strength for the Vacancy Year, entered year by year with its order. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>1. {{ __('vacancymaster.sanctioned.heading', ['year' => $year]) }}</strong></div>
    <div class="card-body">
        <p class="small text-muted">{{ __('vacancymaster.sanctioned.intro', ['year' => $year]) }}</p>

        @if ($sanctioned === null)
            <div class="alert alert-light border small">{{ __('vacancymaster.sanctioned.not_entered_help') }}</div>
        @else
            <dl class="row small mb-3">
                <dt class="col-sm-4">{{ __('vacancymaster.sanctioned.total') }}</dt>
                <dd class="col-sm-8 fs-5 fw-semibold">{{ $sanctioned->sanctioned_total }}</dd>
                <dt class="col-sm-4">{{ __('vacancymaster.order') }}</dt>
                <dd class="col-sm-8">
                    {{ $sanctioned->order_number }} · {{ $sanctioned->order_date->format('d-m-Y') }} ·
                    <a href="{{ route('admin.departments.vacancy-master.sanctioned-strength.document', [$department, $year, $post, $sanctioned]) }}">{{ $sanctioned->original_name }}</a>
                </dd>
                @if ($sanctioned->remarks)
                    <dt class="col-sm-4">{{ __('vacancymaster.remarks') }}</dt><dd class="col-sm-8">{{ $sanctioned->remarks }}</dd>
                @endif
            </dl>
        @endif

        @if ($isDraft)
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.departments.vacancy-master.sanctioned-strength', [$department, $year, $post]) }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small" for="sanctioned_total">{{ __('vacancymaster.sanctioned.total') }}</label>
                        <input type="number" min="0" class="form-control form-control-sm" id="sanctioned_total" name="sanctioned_total" value="{{ old('sanctioned_total', $sanctioned?->sanctioned_total) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="ss_order_number">{{ __('vacancymaster.order_number') }}</label>
                        <input type="text" maxlength="120" class="form-control form-control-sm" id="ss_order_number" name="order_number" value="{{ old('order_number') }}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="ss_order_date">{{ __('vacancymaster.order_date') }}</label>
                        <input type="date" class="form-control form-control-sm" id="ss_order_date" name="order_date" value="{{ old('order_date') }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="ss_document">{{ __('vacancymaster.document') }}</label>
                        <input type="file" accept="application/pdf" class="form-control form-control-sm" id="ss_document" name="document" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="ss_remarks">{{ __('vacancymaster.remarks') }}</label>
                        <textarea class="form-control form-control-sm" id="ss_remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                    </div>
                    @if ($sanctioned !== null)
                        <div class="col-md-6">
                            <label class="form-label small" for="ss_correction_reason">{{ __('vacancymaster.sanctioned.correction_reason') }}</label>
                            <textarea class="form-control form-control-sm" id="ss_correction_reason" name="correction_reason" rows="2" maxlength="2000" required>{{ old('correction_reason') }}</textarea>
                        </div>
                    @endif
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm">{{ $sanctioned === null ? __('vacancymaster.sanctioned.save') : __('vacancymaster.sanctioned.correct') }}</button>
                    </div>
                </div>
            </form>
        @endif

        @if ($position->sanctionedStrengthHistory->count() > 1)
            <h3 class="h6 mt-3">{{ __('vacancymaster.sanctioned.history') }}</h3>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead><tr>
                        <th>{{ __('vacancymaster.sanctioned.total') }}</th><th>{{ __('vacancymaster.order') }}</th>
                        <th>{{ __('vacancymaster.sanctioned.correction_reason') }}</th><th>{{ __('vacancymaster.by') }}</th><th>{{ __('vacancymaster.at') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($position->sanctionedStrengthHistory as $row)
                            <tr>
                                <td>{{ $row->sanctioned_total }}</td>
                                <td>{{ $row->order_number }} · <a href="{{ route('admin.departments.vacancy-master.sanctioned-strength.document', [$department, $year, $post, $row]) }}">{{ $row->original_name }}</a></td>
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
