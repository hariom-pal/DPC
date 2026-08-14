@extends('layouts.app')

@section('title', 'Proceeding History')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Proceeding History</h1>
            <p class="text-muted small mb-3">
                Every step of this DPC's proceeding, in the order it happened, with the person who took it as
                they were named at the time. This history is read from the workflow record itself, not from
                the current state of the DPC — renaming a user or changing a record later cannot rewrite it.
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:14rem">When</th>
                                    <th>Event</th>
                                    <th>By</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($entries as $entry)
                                @php $properties = $entry->properties ?? []; @endphp
                                <tr>
                                    <td>{{ $entry->occurred_at?->format('d/m/Y h:i A') }}</td>
                                    <td>{{ $entry->description ?? $entry->event }}</td>
                                    <td>{{ $entry->actor_name ?? '—' }}</td>
                                    <td class="small">
                                        @if (! empty($properties['dpc_date']))
                                            Scheduled for {{ $properties['dpc_date'] }}
                                            {{ $properties['dpc_time'] ?? '' }}.
                                        @endif
                                        @if (! empty($properties['opened_at']))
                                            Opened {{ \Illuminate\Support\Carbon::parse($properties['opened_at'])->format('d/m/Y h:i A') }}.
                                        @endif
                                        @if (! empty($properties['remarks']))
                                            <div>{{ $properties['remarks'] }}</div>
                                        @endif
                                        @if (! empty($properties['proceeding_uuid']))
                                            <div class="text-muted">
                                                Reference <code>{{ $properties['proceeding_uuid'] }}</code>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">
                                    Nothing has been recorded for this proceeding yet.
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <a class="btn btn-sm btn-link" href="{{ route('cgpsc.proceedings.show', $proceeding) }}">Back</a>
            </div>
        </div>
    </div>
@endsection
