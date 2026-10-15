@extends('layouts.app')

@section('title', __('admin.audit_trail.index.audit_trail'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('admin.audit_trail.index.audit_trail') }}</h1>
            <p class="text-muted small mb-3">
{!! __('admin.audit_trail.index.lead') !!}
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
{{ __('admin.audit_trail.index.source_workflow') }}
                                            @break
                                        @case('timeline')
{{ __('admin.audit_trail.index.source_timeline') }}
                                            @break
                                        @default
{{ __('admin.audit_trail.index.source_data') }}
                                    @endswitch
                                </p>
                                @if (($counts[$key] ?? 0) === 0)
                                    <span class="badge text-bg-warning mt-2">{{ __('admin.audit_trail.index.no_entries') }}</span>
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
                            <label for="source" class="form-label small mb-1">{{ __('ui.record') }}</label>
                            <select id="source" name="source" class="form-select form-select-sm">
                                <option value="">{{ __('admin.audit_trail.index.all_three') }}</option>
                                @foreach ($sources as $key => $label)
                                    <option value="{{ $key }}" @selected(($filters['source'] ?? '') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="event" class="form-label small mb-1">{{ __('ui.event') }}</label>
                            <input type="text" id="event" name="event" class="form-control form-control-sm"
                                   value="{{ $filters['event'] ?? '' }}" placeholder="{{ __('admin.audit_trail.index.approve_publish') }}">
                        </div>
                        <div class="col-md-2">
                            <label for="actor" class="form-label small mb-1">{{ __('admin.audit_trail.index.actor') }}</label>
                            <input type="text" id="actor" name="actor" class="form-control form-control-sm"
                                   value="{{ $filters['actor'] ?? '' }}" placeholder="{{ __('admin.audit_trail.index.name') }}">
                        </div>
                        <div class="col-md-2">
                            <label for="subject_type" class="form-label small mb-1">{{ __('ui.subject') }}</label>
                            <select id="subject_type" name="subject_type" class="form-select form-select-sm">
                                <option value="">{{ __('admin.audit_trail.index.any') }}</option>
                                @foreach ($subjectTypes as $type)
                                    <option value="{{ $type }}" @selected(($filters['subject_type'] ?? '') === $type)>
                                        {{ class_basename($type) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label for="subject_id" class="form-label small mb-1">{{ __('admin.audit_trail.index.id') }}</label>
                            <input type="text" id="subject_id" name="subject_id" class="form-control form-control-sm"
                                   value="{{ $filters['subject_id'] ?? '' }}">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <div class="flex-fill">
                                <label for="from" class="form-label small mb-1">{{ __('ui.from') }}</label>
                                <input type="date" id="from" name="from" class="form-control form-control-sm"
                                       value="{{ $filters['from'] ?? '' }}">
                            </div>
                            <div class="flex-fill">
                                <label for="to" class="form-label small mb-1">{{ __('ui.to') }}</label>
                                <input type="date" id="to" name="to" class="form-control form-control-sm"
                                       value="{{ $filters['to'] ?? '' }}">
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button class="btn btn-primary btn-sm">{{ __('admin.audit_trail.index.search') }}</button>
                            <a href="{{ route('admin.audit-trail.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('admin.audit_trail.index.clear') }}</a>
                            <a href="{{ route('admin.audit-trail.export', $filters) }}" class="btn btn-outline-secondary btn-sm ms-auto">
                                {{ __('admin.audit_trail.index.export_csv') }}
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
                                    <th>{{ __('ui.when') }}</th>
                                    <th>{{ __('ui.record') }}</th>
                                    <th>{{ __('ui.event') }}</th>
                                    <th>{{ __('ui.detail') }}</th>
                                    <th>{{ __('admin.audit_trail.index.actor') }}</th>
                                    <th>{{ __('ui.subject') }}</th>
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
                                               {{ __('admin.audit_trail.index.view') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-muted text-center py-4">
                                            {{ __('admin.audit_trail.index.nothing_recorded_matches_these_filters') }}
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
