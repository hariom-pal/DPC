@extends('layouts.app')

@section('title', 'Audit Trail')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Audit Trail</h1>
            <p class="text-muted small mb-3">
                Everything this system records about what happened, from all three of its records.
                <strong>Read-only</strong> — nothing here can be edited, deleted or purged from the
                application, and retention is permanent.
            </p>

            {{-- The three counts, first. An operator's first question about an
                 audit record is not "what does it say" but "is it being written
                 at all", and a zero here answers it immediately. --}}
            <div class="row g-3 mb-3">
                @foreach ($sources as $key => $label)
                    <div class="col-sm-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="small text-muted">{{ $label }}</span>
                                    <span class="h5 mb-0">{{ number_format($counts[$key] ?? 0) }}</span>
                                </div>
                                <p class="small text-muted mb-0">
                                    @switch($key)
                                        @case('workflow')
                                            The legal ledger — every state change, its actor, remarks, signature and IP.
                                            @break
                                        @case('timeline')
                                            Domain events, with a snapshot of who the actor was at the time.
                                            @break
                                        @default
                                            Model-level changes, old value beside new.
                                    @endswitch
                                </p>
                                @if (($counts[$key] ?? 0) === 0)
                                    <span class="badge text-bg-warning mt-2">No entries</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.audit-trail.index') }}" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label for="source" class="form-label small mb-1">Record</label>
                            <select id="source" name="source" class="form-select form-select-sm">
                                <option value="">All three</option>
                                @foreach ($sources as $key => $label)
                                    <option value="{{ $key }}" @selected(($filters['source'] ?? '') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="event" class="form-label small mb-1">Event</label>
                            <input type="text" id="event" name="event" class="form-control form-control-sm"
                                   value="{{ $filters['event'] ?? '' }}" placeholder="approve, publish…">
                        </div>
                        <div class="col-md-2">
                            <label for="actor" class="form-label small mb-1">Actor</label>
                            <input type="text" id="actor" name="actor" class="form-control form-control-sm"
                                   value="{{ $filters['actor'] ?? '' }}" placeholder="name">
                        </div>
                        <div class="col-md-2">
                            <label for="subject_type" class="form-label small mb-1">Subject</label>
                            <select id="subject_type" name="subject_type" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach ($subjectTypes as $type)
                                    <option value="{{ $type }}" @selected(($filters['subject_type'] ?? '') === $type)>
                                        {{ class_basename($type) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label for="subject_id" class="form-label small mb-1">ID</label>
                            <input type="text" id="subject_id" name="subject_id" class="form-control form-control-sm"
                                   value="{{ $filters['subject_id'] ?? '' }}">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <div class="flex-fill">
                                <label for="from" class="form-label small mb-1">From</label>
                                <input type="date" id="from" name="from" class="form-control form-control-sm"
                                       value="{{ $filters['from'] ?? '' }}">
                            </div>
                            <div class="flex-fill">
                                <label for="to" class="form-label small mb-1">To</label>
                                <input type="date" id="to" name="to" class="form-control form-control-sm"
                                       value="{{ $filters['to'] ?? '' }}">
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button class="btn btn-primary btn-sm">Search</button>
                            <a href="{{ route('admin.audit-trail.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                            <a href="{{ route('admin.audit-trail.export', $filters) }}" class="btn btn-outline-secondary btn-sm ms-auto">
                                Export CSV
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Record</th>
                                    <th>Event</th>
                                    <th>Detail</th>
                                    <th>Actor</th>
                                    <th>Subject</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($entries as $entry)
                                    <tr>
                                        <td class="text-nowrap small">
                                            {{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->format('d-m-Y H:i') }}
                                        </td>
                                        <td>
                                            <span class="badge text-bg-light">{{ $sources[$entry->source] ?? $entry->source }}</span>
                                        </td>
                                        <td class="small"><code>{{ $entry->event }}</code></td>
                                        <td class="small">{{ $entry->summary }}</td>
                                        <td class="small">
                                            {{-- An unattributed entry is a fact, not a gap: a
                                                 system-generated event has no human actor. --}}
                                            {{ $entry->actor_name ?: '—' }}
                                        </td>
                                        <td class="small">
                                            @if ($entry->subject_type)
                                                {{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.audit-trail.show', [$entry->source, $entry->source_id]) }}"
                                               class="btn btn-outline-secondary btn-sm">View</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-muted text-center py-4">
                                            Nothing recorded matches these filters.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">{{ $entries->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
