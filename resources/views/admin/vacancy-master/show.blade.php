@extends($layout)

@section('title', __('vacancymaster.title').' — '.$post->name)

@section('content')
    @php
        $isDraft = $position !== null && $position->isDraft();
        $isLatestFinalized = $position !== null && $position->status->value === 'finalized';
        // Forms only for the department's Authorized Officer; everyone else reads.
        $editable = $isDraft && $canManage;
        // Section-wise revision (2026-11-13): the AO edits only what the AH unlocked,
        // and the AH's Edit on a section opens (or widens) the revision.
        $sectionEditable = fn (string $key): bool => $editable && $position->sectionUnlocked($key);
        $canUnlock = fn (string $key): bool => $canApprove && $position !== null
            && ($isLatestFinalized || ($isDraft && $position->isSectionRevision() && ! $position->sectionUnlocked($key)));
        $isPending = $position !== null && $position->isPendingApproval();
        $sanctioned = $position?->sanctionedStrength;
        $working = $position?->workingStrength;
        $b = $breakdown;

        $activeEntries = $position === null ? collect() : $position->entries->filter(fn ($entry) => $entry->isActive());
        $pendingEntries = $activeEntries->filter(fn ($entry) => $entry->eligibility->value === 'pending_verification')->count();
        // A record kept before 2026-10-30 and frozen then is read the old way.
        $perPreDpc = $position === null || $position->isDraft() || $position->isPendingApproval() || \App\Domain\Dpc\Services\VacancyMasterService::isPerPreDpc($b);

        // One question per step. Each step knows whether it is done. A record kept per
        // Pre-DPC enters sanctioned and working strength on one screen (2026-10-31).
        $vacanciesState = $pendingEntries > 0 ? 'pending' : ($activeEntries->isNotEmpty() ? 'done' : 'optional');
        // Done only once finalized — "ready" is not done.
        $summaryState = $position !== null && ! $isDraft && ! $isPending ? 'done' : 'pending';
        if ($perPreDpc) {
            $section = $section === 'working' ? 'sanctioned' : $section;
            $steps = [
                'sanctioned' => ['n' => 1, 'state' => $sanctioned !== null && $working !== null ? 'done' : 'pending'],
                'vacancies' => ['n' => 2, 'state' => $vacanciesState],
                'summary' => ['n' => 3, 'state' => $summaryState],
            ];
        } else {
            $steps = [
                'sanctioned' => ['n' => 1, 'state' => $sanctioned !== null ? 'done' : 'pending'],
                'working' => ['n' => 2, 'state' => $working !== null && $working->hasQuotaSplit() ? 'done' : 'pending'],
                'vacancies' => ['n' => 3, 'state' => $vacanciesState],
                'summary' => ['n' => 4, 'state' => $summaryState],
            ];
        }
        $stepLabel = fn (string $key): string => __('vacancymaster.steps.'.($perPreDpc && $key === 'sanctioned' ? 'strength' : $key));
        $order = array_keys($steps);
        // Without saving a step there is no going on to the next (UAT, 2026-11-02): a
        // step opens only once every step before it is saved (or has nothing to save).
        $reachable = [];
        $open = true;
        foreach ($steps as $key => $step) {
            $reachable[$key] = $open;
            $open = $open && $step['state'] !== 'pending';
        }
        if (! ($reachable[$section] ?? false)) {
            $section = collect($order)->last(fn (string $key): bool => $reachable[$key]) ?? $order[0];
        }
        $index = array_search($section, $order, true);
        $previous = $index > 0 ? $order[$index - 1] : null;
        $next = $index < count($order) - 1 ? $order[$index + 1] : null;
        $stepUrl = fn (string $key): string => route($records.'.show', [$department, $year, $post, 'section' => $key]);
    @endphp

    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route($management.'.index') }}">{{ __('vacancymaster.title') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route($records.'.index', [$department, 'year' => $year]) }}">{{ __('vacancymaster.title') }} {{ $year }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $post->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $post->name }}</h1>
            <div class="text-muted small">
                {{ __('vacancymaster.department_label') }}: <strong>{{ $department->name }}</strong>
                · {{ __('vacancymaster.vacancy_year') }}: <strong>{{ $year }}</strong>
                {{-- Fixed, as on the Pre-DPC (2026-11-05): 1 January of the DPC year. --}}
                · {{ __('predpc.form.publish_date') }}: <strong data-effective-on>01-01-{{ $year }}</strong>
                · {{ __('vacancymaster.year_window', ['from' => '01-01-'.$year, 'to' => '31-12-'.$year]) }}
            </div>
            <div class="text-muted small">{{ __('vacancymaster.seniority_reference', ['date' => $seniorityReference->format('d-m-Y')]) }}</div>
            {{-- Not on a record finalized before 2026-10-30: it took its share from the Recruitment Rule. --}}
            @if ($preDpc !== null && $perPreDpc)
                <div class="small" id="vacancy-pre-dpc">
                    {{ __('vacancymaster.pm.for_pre_dpc') }}:
                    <a href="{{ route('pre-dpc.show', $preDpc) }}"><code>{{ $preDpc->pre_dpc_number ?? '—' }}</code></a>
                    · {{ __('vacancymaster.pm.promotion_share', ['pct' => $preDpc->promotion_recruitment_pct]) }}
                </div>
            @endif
        </div>
        <div class="text-end">
            @if ($position !== null)
                <span class="badge {{ $position->status->value === 'finalized' ? 'text-bg-success' : ($position->status->value === 'pending_approval' ? 'text-bg-info' : 'text-bg-warning') }}">{{ $position->status->label() }}</span>
                <div class="small text-muted">{{ __('vacancymaster.revision_n', ['revision' => $position->revision]) }}</div>
            @endif
        </div>
    </div>

    @include('partials.alerts', ['inlineErrors' => true])

    @unless ($canManage)
        <div class="alert alert-secondary py-2 small" id="vacancy-read-only">{{ __('vacancymaster.read_only_note') }}</div>
    @endunless

    @if ($position === null)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <p class="mb-2">{{ __('vacancymaster.no_position_yet', ['year' => $year]) }}</p>
                @if ($canManage)
                    <form method="POST" action="{{ route($records.'.open', [$department, $year, $post]) }}">
                        @csrf
                        <button class="btn btn-primary btn-sm">{{ __('vacancymaster.open_draft') }}</button>
                    </form>
                @else
                    <p class="small text-muted mb-0">{{ __('vacancymaster.read_only_note') }}</p>
                @endif
            </div>
        </div>
    @else
        @if ($position->revision_reason)
            <div class="alert alert-info small">{{ __('vacancymaster.revision_reason_label') }}: {{ $position->revision_reason }}</div>
        @endif

        {{-- Sent to the AH with its Pre-DPC, or returned with it (2026-11-19). --}}
        @if ($position->isPendingWithPreDpc())
            <div class="alert alert-info small" id="combined-pending">
                {{ __('vacancymaster.combined.pending_banner', ['number' => $position->preDpcProposal?->pre_dpc_number ?? '—', 'at' => $position->submitted_at?->format('d-m-Y H:i') ?? '—']) }}
            </div>
        @elseif ($isDraft && $position->submitted_with_pre_dpc_id !== null && $position->return_remarks)
            <div class="alert alert-danger small" id="combined-returned">
                {{ __('vacancymaster.combined.returned_banner', ['number' => $position->preDpcProposal?->pre_dpc_number ?? '—', 'at' => $position->returned_at?->format('d-m-Y H:i') ?? '—', 'remarks' => $position->return_remarks]) }}
            </div>
        @endif

        @if ($position->isSectionRevision() && ($isDraft || $isPending))
            @php
                $unlockedNames = collect($position->unlocked_sections)->map(fn ($key) => __('vacancymaster.sections.'.$key))->implode(', ');
            @endphp
            @if ($isDraft)
                <div class="alert alert-warning small" id="section-revision-banner">
                    {{ __('vacancymaster.section_revision.banner', ['revision' => $position->revision, 'sections' => $unlockedNames]) }}
                </div>
                @if ($position->return_remarks)
                    <div class="alert alert-danger small" id="section-revision-returned">
                        {{ __('vacancymaster.section_revision.returned', ['at' => $position->returned_at?->format('d-m-Y H:i') ?? '—', 'remarks' => $position->return_remarks]) }}
                    </div>
                @endif
            @elseif ($canApprove)
                <div class="card border-info shadow-sm mb-3" id="section-revision-decision">
                    <div class="card-body">
                        <p class="small mb-2">{{ __('vacancymaster.section_revision.pending_ah', ['revision' => $position->revision, 'at' => $position->submitted_at?->format('d-m-Y H:i') ?? '—', 'sections' => $unlockedNames]) }}</p>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <form method="POST" action="{{ route($records.'.approve-revision', [$department, $year, $post]) }}" data-confirm="{{ __('vacancymaster.section_revision.approve_confirm') }}">
                                @csrf
                                <button class="btn btn-success btn-sm">{{ __('vacancymaster.section_revision.approve') }}</button>
                            </form>
                            <form method="POST" action="{{ route($records.'.return-revision', [$department, $year, $post]) }}" class="flex-grow-1">
                                @csrf
                                <label class="form-label small mb-1" for="return_remarks">{{ __('vacancymaster.section_revision.return_remarks') }}</label>
                                <textarea class="form-control form-control-sm mb-2 @error('return_remarks') is-invalid @enderror" id="return_remarks" name="return_remarks" rows="2" maxlength="2000" required>{{ old('return_remarks') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'return_remarks'])
                                <button class="btn btn-outline-danger btn-sm">{{ __('vacancymaster.section_revision.return') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                <div class="alert alert-info small" id="section-revision-pending">
                    {{ __('vacancymaster.section_revision.pending_ao', ['at' => $position->submitted_at?->format('d-m-Y H:i') ?? '—']) }}
                </div>
            @endif
        @endif

        {{-- The four steps, one screen each. --}}
        <nav aria-label="{{ __('vacancymaster.steps.nav_label') }}" class="mb-3">
            <ol class="nav nav-pills flex-column flex-md-row gap-2 list-unstyled mb-0">
                @foreach ($steps as $key => $step)
                    <li class="nav-item flex-md-fill">
                        <a @if ($reachable[$key]) href="{{ $stepUrl($key) }}" @else aria-disabled="true" title="{{ __('vacancymaster.steps.save_first') }}" @endif
                           @class(['nav-link border h-100', 'active' => $section === $key, 'disabled' => ! $reachable[$key]])
                           data-step="{{ $key }}"
                           @if ($section === $key) aria-current="step" @endif>
                            <span class="fw-semibold">{{ $step['n'] }}. {{ $stepLabel($key) }}</span>
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

        @include('admin.vacancy-master.steps.'.($perPreDpc && $section === 'sanctioned' ? 'strength' : $section.($perPreDpc || $section === 'sanctioned' ? '' : '-v1')))

        <div class="d-flex justify-content-between mb-3">
            <div>
                @if ($previous)
                    <a class="btn btn-outline-secondary btn-sm" href="{{ $stepUrl($previous) }}">&larr; {{ __('vacancymaster.steps.back', ['step' => $stepLabel($previous)]) }}</a>
                @endif
            </div>
            <div>
                @if ($next && $reachable[$next])
                    <a class="btn btn-outline-primary btn-sm" href="{{ $stepUrl($next) }}">{{ __('vacancymaster.steps.next', ['step' => $stepLabel($next)]) }} &rarr;</a>
                @elseif ($next)
                    <span class="small text-muted me-2" id="vacancy-save-first">{{ __('vacancymaster.steps.save_first') }}</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" disabled>{{ __('vacancymaster.steps.next', ['step' => $stepLabel($next)]) }} &rarr;</button>
                @endif
            </div>
        </div>
    @endif

    {{-- The record's trail: every step of every revision, newest first; folded until asked for. --}}
    <details class="card border-0 shadow-sm mt-3" id="vacancy-trail">
        <summary class="card-header bg-white"><strong>{{ __('vacancymaster.trail.heading') }}</strong>
            <span class="badge text-bg-light border">{{ count($trail) }}</span>
            <span class="small text-muted">{{ __('vacancymaster.trail.lead') }}</span></summary>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr>
                    <th style="width:11rem">{{ __('vacancymaster.trail.when') }}</th>
                    <th style="width:12rem">{{ __('vacancymaster.trail.who') }}</th>
                    <th style="width:6rem">{{ __('vacancymaster.trail.revision') }}</th>
                    <th>{{ __('vacancymaster.trail.what') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($trail as $event)
                        <tr data-trail-event="{{ $event->event }}">
                            <td class="small">{{ $event->occurred_at?->format('d-m-Y H:i') }}</td>
                            <td class="small">{{ $event->actor_name ?? '—' }}</td>
                            <td class="small">{{ $event->properties['revision'] ?? '—' }}</td>
                            <td class="small">{{ $event->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ __('vacancymaster.trail.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>
@endsection
