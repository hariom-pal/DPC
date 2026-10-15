@extends('layouts.app')

@section('title', __('admin.audit_trail.show.audit_entry'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <a href="{{ url()->previous() }}" class="btn btn-link btn-sm px-0 mb-2">{!! __('admin.audit_trail.show.back') !!}</a>

            <h1 class="h5 mb-1">{{ $sources[$source] ?? $source }}</h1>
            <p class="text-muted small mb-3">
                {{ __('admin.audit_trail.show.the_record_itself_not_the_projection_shown_in_th') }}
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
                                    __('admin.audit_trail.show.fields.workflow') => $entry->workflow,
                                    __('admin.audit_trail.show.fields.event') => $entry->event,
                                    __('admin.audit_trail.show.fields.transition') => ($entry->from_state ?? __('admin.audit_trail.show.no_state')).' → '.$entry->to_state,
                                    __('admin.audit_trail.show.fields.occurred_at') => $entry->occurred_at?->format('d-m-Y H:i:s'),
                                    __('admin.audit_trail.show.fields.actor') => $entry->actor?->name ?? '—',
                                    __('admin.audit_trail.show.fields.remarks') => $entry->remarks ?: '—',
                                    __('admin.audit_trail.show.fields.signature') => $entry->signature_id === null
                                        ? __('admin.audit_trail.show.no_signature')
                                        : __('admin.audit_trail.show.signature_reference', ['id' => $entry->signature_id]),
                                    __('admin.audit_trail.show.fields.ip_address') => $entry->ip_address ?: '—',
                                    __('admin.audit_trail.show.fields.uuid') => $entry->uuid,
                                ],
                                'timeline' => [
                                    __('admin.audit_trail.show.fields.event') => $entry->event,
                                    __('admin.audit_trail.show.fields.description') => $entry->description ?: '—',
                                    __('admin.audit_trail.show.fields.occurred_at') => $entry->occurred_at?->format('d-m-Y H:i:s'),
                                    // The snapshot, not the live user — that is what
                                    // this record exists to preserve.
                                    __('admin.audit_trail.show.fields.actor_snapshot') => $entry->actor_name ?: __('admin.audit_trail.show.system_actor'),
                                    __('admin.audit_trail.show.fields.actor_uuid') => $entry->actor_uuid ?: '—',
                                    __('admin.audit_trail.show.fields.schema_version') => $entry->schema_version,
                                    __('admin.audit_trail.show.fields.uuid') => $entry->uuid,
                                ],
                                default => [
                                    __('admin.audit_trail.show.fields.event') => $entry->event,
                                    __('admin.audit_trail.show.fields.occurred_at') => $entry->created_at?->format('d-m-Y H:i:s'),
                                    __('admin.audit_trail.show.fields.actor') => $entry->user?->name ?? '—',
                                    __('admin.audit_trail.show.fields.ip_address') => $entry->ip_address ?: '—',
                                    __('admin.audit_trail.show.fields.url') => $entry->url ?: '—',
                                    __('admin.audit_trail.show.fields.tags') => $entry->tags ?: '—',
                                ],
                            };
                        @endphp

                        @foreach ($fields as $label => $value)
                            <dt class="col-sm-4">{{ $label }}</dt>
                            <dd class="col-sm-8">{{ $value }}</dd>
                        @endforeach

                        <dt class="col-sm-4">{{ __('ui.subject') }}</dt>
                        <dd class="col-sm-8 mb-0">
                            @php($type = $source === 'data' ? $entry->auditable_type : $entry->subject_type)
                            @php($id = $source === 'data' ? $entry->auditable_id : $entry->subject_id)
                            @if ($type)
                                {{ class_basename($type) }} #{{ $id }}
                                <a class="ms-2 small"
                                   href="{{ route('admin.audit-trail.index', ['subject_type' => $type, 'subject_id' => $id]) }}">
{{ __('admin.audit_trail.show.everything_recorded') }}
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
                        <h2 class="h6">{{ __('admin.audit_trail.show.values_changed') }}</h2>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>{{ __('ui.field') }}</th><th>{{ __('admin.audit_trail.show.before') }}</th><th>{{ __('admin.audit_trail.show.after') }}</th></tr></thead>
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
                                        <tr><td colspan="3" class="text-muted small">{{ __('admin.audit_trail.show.no_values_recorded') }}</td></tr>
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
                            <p class="text-muted small mb-0">{{ __('admin.audit_trail.show.nothing_further_was_recorded') }}</p>
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
