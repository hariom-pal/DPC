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
                <div class="col-md-5">
                    <label class="form-label" for="remarks">{{ __('ui.remarks') }}</label>
                    <input type="text" maxlength="255" class="form-control" id="remarks" name="remarks" value="{{ old('remarks') }}" placeholder="{{ __('admin.cr_grades.remarks_placeholder') }}">
                </div>
                <div class="col-md-2 pt-md-4"><button class="btn btn-primary mt-md-2">{{ __('admin.cr_grades.add_button') }}</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('admin.cr_grades.value') }}</th><th>{{ __('admin.cr_grades.effective_from') }}</th><th>{{ __('ui.remarks') }}</th><th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($grades as $grade)
                        <tr>
                            <td class="fw-semibold">{{ $grade->value }}</td>
                            <td>{{ $grade->effective_from->format('d-m-Y') }}
                                @if ($grade->effective_from->isFuture())<span class="badge text-bg-info">{{ __('admin.cr_grades.upcoming') }}</span>@endif</td>
                            <td class="small">{{ $grade->remarks ?? '—' }}</td>
                            <td class="text-end">
                                @if ($grade->effective_from->isFuture())
                                    <form method="POST" action="{{ route('admin.cr-grades.destroy', $grade) }}" class="d-inline" data-confirm="{{ __('admin.cr_grades.remove_confirm') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('admin.cr_grades.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
