@extends('layouts.app')

@section('title', 'Scheduling History')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Scheduling History</h1>
            <p class="text-muted small mb-1">
                {{ $proposal->department->name }} ·
                <span class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</span> ·
                {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}
            </p>

            <div class="alert alert-light border small">
                Read <strong>entirely from timeline records</strong> — not reconstructed from the current schedule.
                Every acceptance, scheduling and re-scheduling ever recorded appears below, with the detail captured
                at the moment it happened.
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($events as $event)
                            @php($p = $event->properties ?? [])
                            <li class="list-group-item px-0">
                                @if ($event->event === 'dpc_cgpsc.accept')
                                    <span class="badge text-bg-info">Accepted</span>
                                    <strong>{{ $p['accepted_by_name'] ?? $event->actor_name ?? '—' }}</strong>
                                    accepted the DPC
                                @else
                                    <span class="badge {{ $event->event === 'dpc_cgpsc.reschedule' ? 'text-bg-warning' : 'text-bg-success' }}">
                                        {{ $event->event === 'dpc_cgpsc.reschedule' ? 'Re-scheduled' : 'Scheduled' }}
                                    </span>
                                    <strong>{{ \Illuminate\Support\Carbon::parse($p['dpc_date'] ?? null)->format('d/m/Y') }}</strong>
                                    at
                                    <strong>{{ \Illuminate\Support\Carbon::parse($p['dpc_time'] ?? '00:00')->format('h:i A') }}</strong>

                                    @if (! empty($p['previous_dpc_date']))
                                        <span class="text-muted small">
                                            (previously
                                            <s>{{ \Illuminate\Support\Carbon::parse($p['previous_dpc_date'])->format('d/m/Y') }}
                                               at {{ \Illuminate\Support\Carbon::parse($p['previous_dpc_time'] ?? '00:00')->format('h:i A') }}</s>)
                                        </span>
                                    @endif
                                @endif

                                <div class="small text-muted">
                                    by {{ $event->actor_name ?? '—' }}
                                    on {{ $event->occurred_at->format('d/m/Y H:i') }}
                                    @if (! empty($p['timezone'])) · {{ $p['timezone'] }} @endif
                                </div>

                                @if (! empty($p['remarks']))
                                    <div class="small mt-1"><em>Reason:</em> {{ $p['remarks'] }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">This DPC has not been accepted or scheduled yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('cgpsc.schedules.index') }}" class="btn btn-link mt-2">← Back to scheduling</a>
        </div>
    </div>
@endsection
