@extends('layouts.app')

@section('title', 'Allotment History')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Allotment History</h1>
            <p class="text-muted small mb-1">
                {{ $proposal->department->name }} ·
                <span class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</span> ·
                {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}
            </p>

            <div class="alert alert-light border small">
                This history is read <strong>entirely from timeline records</strong> — not reconstructed from the
                current allotment. Every allotment and re-allotment ever recorded appears below, in order, with the
                detail captured at the moment it happened.
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($events as $event)
                            @php($p = $event->properties ?? [])
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <span class="badge {{ $event->event === 'dpc_cgpsc.reallot' ? 'text-bg-warning' : 'text-bg-primary' }}">
                                            {{ $event->event === 'dpc_cgpsc.reallot' ? 'Re-allotted' : 'Allotted' }}
                                        </span>
                                        <strong>{{ $p['allotted_to_name'] ?? '—' }}</strong>

                                        @if (! empty($p['previous_allotted_to_name']))
                                            <span class="text-muted small">
                                                (previously <s>{{ $p['previous_allotted_to_name'] }}</s>)
                                            </span>
                                        @endif

                                        <div class="small text-muted">
                                            by {{ $p['allotted_by_name'] ?? $event->causer?->name ?? '—' }}
                                            on {{ $event->occurred_at->format('d/m/Y H:i') }}
                                            @if (! empty($p['default_dpc_date']))
                                                · default DPC date {{ \Illuminate\Support\Carbon::parse($p['default_dpc_date'])->format('d/m/y') }}
                                            @endif
                                        </div>

                                        @if (! empty($p['remarks']))
                                            <div class="small mt-1"><em>Reason:</em> {{ $p['remarks'] }}</div>
                                        @endif
                                    </div>

                                    @if (! empty($p['allotment_uuid']))
                                        <span class="font-monospace text-muted" style="font-size:.7rem">{{ $p['allotment_uuid'] }}</span>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">This DPC has not been allotted yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('cgpsc.allotments.index') }}" class="btn btn-link mt-2">← Back to allotment</a>
        </div>
    </div>
@endsection
