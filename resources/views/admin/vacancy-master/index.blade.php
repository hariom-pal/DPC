@extends('layouts.admin')

@section('title', __('vacancymaster.title'))

@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('admin.vacancy-management.index') }}">{{ __('vacancymaster.title') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.edit', $department) }}">{{ $department->name }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('vacancymaster.title') }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('vacancymaster.title') }}</h1>
            <div class="text-muted small">
                {{ __('vacancymaster.department_label') }}: <strong>{{ $department->name }}</strong>
            </div>
            <div class="text-muted small">
                {{ __('vacancymaster.year_window', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}
                · {{ __('vacancymaster.seniority_reference', ['date' => $seniorityReference->format('d-m-Y')]) }}
            </div>
        </div>
        <form method="GET" action="{{ route('admin.departments.vacancy-master.index', $department) }}" class="d-flex gap-2 align-items-center">
            <label for="year" class="form-label mb-0 small">{{ __('vacancymaster.vacancy_year') }}</label>
            <select id="year" name="year" class="form-select form-select-sm">
                @foreach ($years as $option)
                    <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">{{ __('vacancymaster.show_year') }}</button>
        </form>
    </div>

    @include('partials.alerts')

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('vacancymaster.col.post') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.sanctioned') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.working') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.existing') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.anticipated') }}</th>
                        <th class="text-end">{{ __('vacancymaster.col.promotion') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $b = $row['breakdown'];
                            // Entered for this year in the Vacancy Master — never the Post Mapping's figure.
                            $sanctioned = $b !== null ? $b['sanctioned'] : null;
                        @endphp
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-end">
                                @if ($row['mapping'] === null && $b === null)
                                    <span class="small text-muted">{{ __('vacancymaster.not_mapped_on', ['date' => '01-01-'.$year]) }}</span>
                                @else
                                    {{ $sanctioned ?? __('vacancymaster.not_recorded') }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($b === null || $b['working'] === null)
                                    <span class="badge text-bg-light border">{{ __('vacancymaster.not_entered') }}</span>
                                @else
                                    {{ $b['working']['total'] }}
                                @endif
                            </td>
                            <td class="text-end">{{ $b['opening_vacancy'] ?? '—' }}</td>
                            <td class="text-end">
                                {{ $b === null ? '—' : $b['anticipated_counted_promotion'] + $b['anticipated_counted_direct'] }}
                            </td>
                            <td class="text-end">
                                @if ($b !== null && ($b['final_quota']['promotion_vacancy'] ?? null) !== null)
                                    {{ $b['final_quota']['promotion_vacancy'] }}
                                @else
                                    <span class="small text-muted">{{ __('vacancymaster.incomplete') }}</span>
                                @endif
                            </td>
                            <td>
                                <span @class([
                                    'badge',
                                    'text-bg-success' => $row['status'] === 'finalized',
                                    'text-bg-primary' => $row['status'] === 'ready',
                                    'text-bg-warning' => in_array($row['status'], ['incomplete', 'working_not_entered'], true),
                                    'text-bg-light border' => $row['status'] === 'not_started',
                                ])>{{ __('vacancymaster.list_status.'.$row['status']) }}</span>
                                @if ($row['position'] !== null && $row['position']->revision > 1)
                                    <span class="small text-muted">{{ __('vacancymaster.revision_n', ['revision' => $row['position']->revision]) }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.departments.vacancy-master.show', [$department, $year, $row['any']]) }}" class="btn btn-outline-primary btn-sm">{{ __('vacancymaster.manage') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">{{ __('vacancymaster.no_posts') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">{{ __('vacancymaster.list_note') }}</p>
@endsection
