@extends('layouts.admin')

@section('title', __('admin.cr_grades.title'))

@section('content')
    <h1 class="h4 mb-1">{{ __('admin.cr_grades.title') }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.cr_grades.intro') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('admin.cr_grades.add') }}</strong></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.cr-grades.store') }}" class="row g-3 align-items-start">
                @csrf
                <div class="col-md-2">
                    <label class="form-label" for="value">{{ __('admin.cr_grades.value') }}</label>
                    <input type="number" min="0" max="1000" class="form-control @error('value') is-invalid @enderror" id="value" name="value" value="{{ old('value') }}" required @error('value') autofocus @enderror>
                    @error('value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="effective_from">{{ __('admin.cr_grades.effective_from') }}</label>
                    <input type="date" class="form-control @error('effective_from') is-invalid @enderror" id="effective_from" name="effective_from" value="{{ old('effective_from') }}" required>
                    @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="grade_code">{{ __('admin.cr_grades.grade_code') }}</label>
                    <select class="form-select @error('grade_code') is-invalid @enderror" id="grade_code" name="grade_code">
                        <option value="">—</option>
                        @foreach ((array) config('dpc.evaluation.cr_grades') as $code)
                            <option value="{{ $code }}" @selected(old('grade_code') === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                    @error('grade_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="remarks">{{ __('ui.remarks') }}</label>
                    <input type="text" maxlength="255" class="form-control" id="remarks" name="remarks" value="{{ old('remarks') }}" placeholder="{{ __('admin.cr_grades.remarks_placeholder') }}">
                </div>
                <div class="col-md-2 pt-md-4"><button class="btn btn-primary mt-md-2">{{ __('admin.cr_grades.add_button') }}</button></div>
            </form>
        </div>
    </div>

    @if ($errors->hasAny(['effective_to', 'deactivation_reason', 'remarks']))
        <div class="alert alert-danger py-2" id="deactivate-errors">
            @foreach (['effective_to', 'deactivation_reason', 'remarks'] as $field)
                @error($field)<div>{{ $message }}</div>@enderror
            @endforeach
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('admin.cr_grades.value') }}</th>
                    <th>{{ __('admin.cr_grades.grade_code') }}</th>
                    <th>{{ __('admin.cr_grades.effective_from') }}</th>
                    <th>{{ __('admin.cr_grades.effective_to') }}</th>
                    <th>{{ __('ui.status') }}</th>
                    <th>{{ __('ui.remarks') }}</th>
                    <th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($grades as $grade)
                        @php
                            $state = $grade->statusOn($today);
                        @endphp
                        <tr data-cr-grade="{{ $grade->id }}">
                            <td class="fw-semibold">{{ $grade->value }}</td>
                            <td>{{ $grade->grade_code ?? '—' }}</td>
                            <td>{{ $grade->effective_from->format('d-m-Y') }}</td>
                            <td>{{ $grade->effective_to?->format('d-m-Y') ?? __('admin.cr_grades.open_ended') }}</td>
                            <td>
                                <span class="badge {{ ['active' => 'text-bg-success', 'upcoming' => 'text-bg-info', 'inactive' => 'text-bg-secondary'][$state] }}">{{ __('admin.cr_grades.status.'.$state) }}</span>
                                @if ($grade->effective_to !== null)
                                    <div class="small text-muted">{{ __('admin.cr_grades.deactivated_note', [
                                        'by' => $deactivators[$grade->deactivated_by] ?? '—',
                                        'at' => $grade->deactivated_at?->format('d-m-Y H:i') ?? '—',
                                    ]) }}</div>
                                    <div class="small">{{ $grade->deactivation_reason }}</div>
                                @endif
                            </td>
                            <td class="small">
                                <span data-cr-grade-remarks="{{ $grade->id }}">{{ $grade->remarks ?? '—' }}</span>
                                <details class="mt-1">
                                    <summary class="small text-primary">{{ __('admin.cr_grades.edit_remarks') }}</summary>
                                    <form method="POST" action="{{ route('admin.cr-grades.remarks', $grade) }}" class="mt-1 d-flex gap-1">
                                        @csrf @method('PATCH')
                                        <input type="text" maxlength="255" class="form-control form-control-sm" name="remarks" value="{{ $grade->remarks }}" aria-label="{{ __('ui.remarks') }}">
                                        <button class="btn btn-outline-primary btn-sm">{{ __('ui.save') }}</button>
                                    </form>
                                </details>
                            </td>
                            <td class="text-end">
                                @if ($state === 'upcoming')
                                    <form method="POST" action="{{ route('admin.cr-grades.destroy', $grade) }}" class="d-inline" data-confirm="{{ __('admin.cr_grades.remove_confirm') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                    </form>
                                @elseif ($grade->effective_to === null)
                                    <details class="text-start">
                                        <summary class="btn btn-outline-secondary btn-sm">{{ __('admin.cr_grades.deactivate') }}</summary>
                                        <form method="POST" action="{{ route('admin.cr-grades.deactivate', $grade) }}" class="mt-2" style="min-width:16rem"
                                              data-confirm="{{ __('admin.cr_grades.deactivate_confirm', ['value' => $grade->value]) }}">
                                            @csrf @method('PATCH')
                                            <label class="form-label small" for="effective_to_{{ $grade->id }}">{{ __('admin.cr_grades.effective_to') }}</label>
                                            <input type="date" class="form-control form-control-sm mb-2" id="effective_to_{{ $grade->id }}" name="effective_to"
                                                   min="{{ $today->toDateString() }}" value="{{ $today->toDateString() }}" required>
                                            <label class="form-label small" for="reason_{{ $grade->id }}">{{ __('admin.cr_grades.deactivation_reason') }}</label>
                                            <input type="text" maxlength="255" class="form-control form-control-sm mb-2" id="reason_{{ $grade->id }}" name="deactivation_reason" required>
                                            <button class="btn btn-warning btn-sm">{{ __('admin.cr_grades.deactivate') }}</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('admin.cr_grades.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
