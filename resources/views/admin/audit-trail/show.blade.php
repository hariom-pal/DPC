@extends('layouts.app')

@section('title', 'Audit Entry')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <a href="{{ url()->previous() }}" class="btn btn-link btn-sm px-0 mb-2">&larr; Back to the trail</a>

            <h1 class="h5 mb-1">{{ $sources[$source] ?? $source }}</h1>
            <p class="text-muted small mb-3">
                The record itself, not the projection shown in the list.
            </p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row small mb-0">
                        @php
                            // Each record has a different shape, so each names its
                            // own fields. A shared field list would either omit what
                            // matters on one record or invent it on another.
                            $fields = match ($source) {
                                'workflow' => [
                                    'Workflow' => $entry->workflow,
                                    'Event' => $entry->event,
                                    'Transition' => ($entry->from_state ?? '(none)').' → '.$entry->to_state,
                                    'Occurred at' => $entry->occurred_at?->format('d-m-Y H:i:s'),
                                    'Actor' => $entry->actor?->name ?? '—',
                                    'Remarks' => $entry->remarks ?: '—',
                                    'Signature' => $entry->signature_id === null
                                        ? 'None — this transition carried no signature'
                                        : 'Signature #'.$entry->signature_id,
                                    'IP address' => $entry->ip_address ?: '—',
                                    'UUID' => $entry->uuid,
                                ],
                                'timeline' => [
                                    'Event' => $entry->event,
                                    'Description' => $entry->description ?: '—',
                                    'Occurred at' => $entry->occurred_at?->format('d-m-Y H:i:s'),
                                    // The snapshot, not the live user — that is what
                                    // this record exists to preserve.
                                    'Actor (as recorded then)' => $entry->actor_name ?: '— system',
                                    'Actor UUID' => $entry->actor_uuid ?: '—',
                                    'Schema version' => $entry->schema_version,
                                    'UUID' => $entry->uuid,
                                ],
                                default => [
                                    'Event' => $entry->event,
                                    'Occurred at' => $entry->created_at?->format('d-m-Y H:i:s'),
                                    'Actor' => $entry->user?->name ?? '—',
                                    'IP address' => $entry->ip_address ?: '—',
                                    'URL' => $entry->url ?: '—',
                                    'Tags' => $entry->tags ?: '—',
                                ],
                            };
                        @endphp

                        @foreach ($fields as $label => $value)
                            <dt class="col-sm-4">{{ $label }}</dt>
                            <dd class="col-sm-8">{{ $value }}</dd>
                        @endforeach

                        <dt class="col-sm-4">Subject</dt>
                        <dd class="col-sm-8 mb-0">
                            @php($type = $source === 'data' ? $entry->auditable_type : $entry->subject_type)
                            @php($id = $source === 'data' ? $entry->auditable_id : $entry->subject_id)
                            @if ($type)
                                {{ class_basename($type) }} #{{ $id }}
                                <a class="ms-2 small"
                                   href="{{ route('admin.audit-trail.index', ['subject_type' => $type, 'subject_id' => $id]) }}">
                                    everything recorded about it
                                </a>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            @if ($source === 'data')
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6">Values changed</h2>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                <tbody>
                                    @php($old = (array) $entry->old_values)
                                    @php($new = (array) $entry->new_values)
                                    @forelse (array_keys($old + $new) as $key)
                                        <tr>
                                            <td class="small"><code>{{ $key }}</code></td>
                                            <td class="small text-muted">{{ $old[$key] ?? '—' }}</td>
                                            <td class="small">{{ $new[$key] ?? '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted small">No values recorded.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @else
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6">{{ $source === 'workflow' ? 'Transition metadata' : 'Event properties' }}</h2>
                        @php($properties = (array) ($source === 'workflow' ? $entry->meta : $entry->properties))
                        @if ($properties === [])
                            <p class="text-muted small mb-0">Nothing further was recorded.</p>
                        @else
                            <dl class="row small mb-0">
                                @foreach ($properties as $key => $value)
                                    <dt class="col-sm-4"><code>{{ $key }}</code></dt>
                                    <dd class="col-sm-8">
                                        {{ is_scalar($value) || $value === null
                                            ? (string) ($value ?? '—')
                                            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                    </dd>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
