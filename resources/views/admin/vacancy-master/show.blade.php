@extends('layouts.admin')

@section('title', __('vacancymaster.title').' — '.$post->name)

@section('content')
    @php
        $isDraft = $position !== null && $position->isDraft();
        $isLatestFinalized = $position !== null && $position->status->value === 'finalized';
        $sanctioned = $position?->sanctionedStrength;
        $working = $position?->workingStrength;
        $b = $breakdown;

        $activeEntries = $position === null ? collect() : $position->entries->filter(fn ($entry) => $entry->isActive());
        $pendingEntries = $activeEntries->filter(fn ($entry) => $entry->eligibility->value === 'pending_verification')->count();

        // One question per step. Each step knows whether it is done.
        $steps = [
            'sanctioned' => ['n' => 1, 'state' => $sanctioned !== null ? 'done' : 'pending'],
            'working' => ['n' => 2, 'state' => $working !== null && $working->hasQuotaSplit() ? 'done' : 'pending'],
            'vacancies' => ['n' => 3, 'state' => $pendingEntries > 0 ? 'pending' : ($activeEntries->isNotEmpty() ? 'done' : 'optional')],
            // Done only once finalized — "ready" is not done.
            'summary' => ['n' => 4, 'state' => $position !== null && ! $isDraft ? 'done' : 'pending'],
        ];
        $order = array_keys($steps);
        $index = array_search($section, $order, true);
        $previous = $index > 0 ? $order[$index - 1] : null;
        $next = $index < count($order) - 1 ? $order[$index + 1] : null;
        $stepUrl = fn (string $key): string => route('admin.departments.vacancy-master.show', [$department, $year, $post, 'section' => $key]);
    @endphp

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('admin.vacancy-management.index') }}">{{ __('vacancymaster.title') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.edit', $department) }}">{{ $department->name }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.departments.vacancy-master.index', [$department, 'year' => $year]) }}">{{ __('vacancymaster.title') }} {{ $year }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $post->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $post->name }}</h1>
            <div class="text-muted small">
                {{ __('vacancymaster.department_label') }}: <strong>{{ $department->name }}</strong>
                · {{ __('vacancymaster.vacancy_year') }}: <strong>{{ $year }}</strong>
                · {{ __('vacancymaster.year_window', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}
            </div>
            <div class="text-muted small">{{ __('vacancymaster.seniority_reference', ['date' => $seniorityReference->format('d-m-Y')]) }}</div>
        </div>
        <div class="text-end">
            @if ($position !== null)
                <span class="badge {{ $position->status->value === 'finalized' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $position->status->label() }}</span>
                <div class="small text-muted">{{ __('vacancymaster.revision_n', ['revision' => $position->revision]) }}</div>
            @endif
        </div>
    </div>

    @include('partials.alerts', ['inlineErrors' => true])

    @if ($position === null)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <p class="mb-2">{{ __('vacancymaster.no_position_yet', ['year' => $year]) }}</p>
                <form method="POST" action="{{ route('admin.departments.vacancy-master.open', [$department, $year, $post]) }}">
                    @csrf
                    <button class="btn btn-primary btn-sm">{{ __('vacancymaster.open_draft') }}</button>
                </form>
            </div>
        </div>
    @else
        @if ($position->revision_reason)
            <div class="alert alert-info small">{{ __('vacancymaster.revision_reason_label') }}: {{ $position->revision_reason }}</div>
        @endif

        {{-- The four steps, one screen each. --}}
        <nav aria-label="{{ __('vacancymaster.steps.nav_label') }}" class="mb-3">
            <ol class="nav nav-pills flex-column flex-md-row gap-2 list-unstyled mb-0">
                @foreach ($steps as $key => $step)
                    <li class="nav-item flex-md-fill">
                        <a href="{{ $stepUrl($key) }}"
                           @class(['nav-link border h-100', 'active' => $section === $key])
                           @if ($section === $key) aria-current="step" @endif>
                            <span class="fw-semibold">{{ $step['n'] }}. {{ __('vacancymaster.steps.'.$key) }}</span>
                            <span @class(['badge ms-1',
                                'text-bg-success' => $step['state'] === 'done',
                                'text-bg-warning' => $step['state'] === 'pending',
                                'text-bg-light border' => $step['state'] === 'optional',
                            ])>{{ __('vacancymaster.steps.'.$step['state']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        @include('admin.vacancy-master.steps.'.$section)

        <div class="d-flex justify-content-between mb-3">
            <div>
                @if ($previous)
                    <a class="btn btn-outline-secondary btn-sm" href="{{ $stepUrl($previous) }}">&larr; {{ __('vacancymaster.steps.back', ['step' => __('vacancymaster.steps.'.$previous)]) }}</a>
                @endif
            </div>
            <div>
                @if ($next)
                    <a class="btn btn-outline-primary btn-sm" href="{{ $stepUrl($next) }}">{{ __('vacancymaster.steps.next', ['step' => __('vacancymaster.steps.'.$next)]) }} &rarr;</a>
                @endif
            </div>
        </div>
    @endif
@endsection
