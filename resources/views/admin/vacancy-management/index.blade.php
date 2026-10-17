@extends('layouts.admin')

@section('title', __('vacancymaster.title'))

@section('content')
    <h1 class="h4 mb-1">{{ __('vacancymaster.title') }}</h1>
    <p class="text-muted small mb-3">{{ __('vacancymaster.management.lead') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    {{-- Step 1: Department → Post → Year. Choosing a department reloads its posts. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('vacancymaster.management.open_heading') }}</strong></div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.vacancy-management.index') }}" class="row g-2 align-items-end" id="vacancy-picker">
                <div class="col-md-4">
                    <label class="form-label small" for="picker-department">{{ __('vacancymaster.management.department') }}</label>
                    <select class="form-select" id="picker-department" name="department_id" data-submit-on-change>
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="picker-post">{{ __('vacancymaster.management.post') }}</label>
                    <select class="form-select @error('post') is-invalid @enderror" id="picker-post" name="post" @disabled($posts->isEmpty())>
                        <option value="">{{ $filters['department_id'] === null ? __('vacancymaster.management.choose_department_first') : __('ui.select') }}</option>
                        @foreach ($posts as $name)
                            <option value="{{ $name }}" @selected(old('post', $filters['post']) === $name)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('post')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @if ($filters['department_id'] !== null && $posts->isEmpty())
                        <div class="form-text">{{ __('vacancymaster.no_posts') }}</div>
                    @endif
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="picker-year">{{ __('vacancymaster.vacancy_year') }}</label>
                    <select class="form-select" id="picker-year" name="year">
                        @foreach ($years as $option)
                            <option value="{{ $option }}" @selected(($filters['year'] ?? $currentYear) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" formaction="{{ route('admin.vacancy-management.go') }}" @disabled($posts->isEmpty())>{{ __('vacancymaster.management.open') }}</button>
                </div>
            </form>
            <p class="small text-muted mb-0 mt-2">{{ __('vacancymaster.management.steps_note') }}</p>
        </div>
    </div>

    {{-- Every record, latest revision first; filter to see one post's years. --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <form method="GET" action="{{ route('admin.vacancy-management.index') }}" class="row g-2 align-items-end">
                <div class="col-12"><strong>{{ __('vacancymaster.management.records_heading') }}</strong></div>
                <div class="col-md-3">
                    <label class="form-label small" for="filter-department">{{ __('vacancymaster.management.department') }}</label>
                    <select class="form-select form-select-sm" id="filter-department" name="department_id">
                        <option value="">{{ __('vacancymaster.management.all') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="filter-year">{{ __('vacancymaster.vacancy_year') }}</label>
                    <select class="form-select form-select-sm" id="filter-year" name="year">
                        <option value="">{{ __('vacancymaster.management.all') }}</option>
                        @foreach ($years as $option)
                            <option value="{{ $option }}" @selected($filters['year'] === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="filter-status">{{ __('ui.status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ __('vacancymaster.management.all') }}</option>
                        @foreach (\App\Http\Controllers\Admin\VacancyManagementController::statuses() as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('vacancymaster.status.'.$status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="filter-q">{{ __('vacancymaster.management.search_post') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] }}">
                </div>
                <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">{{ __('vacancymaster.management.filter') }}</button></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr>
                    <th>{{ __('vacancymaster.vacancy_year') }}</th>
                    <th>{{ __('vacancymaster.management.department') }}</th>
                    <th>{{ __('vacancymaster.management.post') }}</th>
                    <th class="text-end">{{ __('vacancymaster.proposal.figures.sanctioned') }}</th>
                    <th class="text-end">{{ __('vacancymaster.proposal.figures.promotion_vacancy') }}</th>
                    <th>{{ __('ui.status') }}</th>
                    <th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        @php
                            $postId = $postIds[$record->department_id.'|'.$record->post_name] ?? null;
                        @endphp
                        <tr data-vacancy-record="{{ $record->uuid }}">
                            <td>{{ $record->vacancy_year }}</td>
                            <td>{{ $record->department?->name }}</td>
                            <td>{{ $record->post_name }}</td>
                            <td class="text-end">{{ $record->sanctioned_strength ?? '—' }}</td>
                            <td class="text-end">{{ $record->promotion_vacancy ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $record->status->value === 'finalized' ? 'text-bg-success' : 'text-bg-warning' }}">{{ __('vacancymaster.status.'.$record->status->value) }}</span>
                                @if ($record->revision > 1)<span class="small text-muted">{{ __('vacancymaster.revision_n', ['revision' => $record->revision]) }}</span>@endif
                            </td>
                            <td class="text-end">
                                @if ($postId !== null)
                                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.departments.vacancy-master.show', [$record->department_id, $record->vacancy_year, $postId]) }}">{{ __('vacancymaster.manage') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('vacancymaster.management.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($records->hasPages())
            <div class="card-body">{{ $records->links() }}</div>
        @endif
    </div>
@endsection
