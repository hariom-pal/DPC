@extends('layouts.app')

@section('title', __('cgpsc.schedules.history.scheduling_history'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.schedules.history.scheduling_history') }}</h1>
            <p class="text-muted small mb-1">
                {{ $proposal->department->name }} ·
                <span class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</span> ·
                {{ $proposal->promotionFromPost->name }} → {{ $proposal->promotionToPost->name }}
            </p>

            <div class="alert alert-light border small">
{!! __('cgpsc.schedules.history.lead') !!}
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($events as $event)
                            @php($p = $event->properties ?? [])
                            <li class="list-group-item px-0">
                                @if ($event->event === 'dpc_cgpsc.accept')
                                    <span class="badge text-bg-info">{{ __('cgpsc.schedules.history.accepted') }}</span>
                                    <strong>{{ $p['accepted_by_name'] ?? $event->actor_name ?? '—' }}</strong>
{{ __('cgpsc.schedules.history.accepted_the_dpc') }}
                                @else
                                    <span class="badge {{ $event->event === 'dpc_cgpsc.reschedule' ? 'text-bg-warning' : 'text-bg-success' }}">
                                        {{ __($event->event === 'dpc_cgpsc.reschedule' ? 'cgpsc.schedules.history.rescheduled' : 'cgpsc.schedules.history.scheduled') }}
                                    </span>
{!! __('cgpsc.schedules.history.date_at_time', [
                                        'date' => \Illuminate\Support\Carbon::parse($p['dpc_date'] ?? null)->format('d/m/Y'),
                                        'time' => \Illuminate\Support\Carbon::parse($p['dpc_time'] ?? '00:00')->format('h:i A'),
                                    ]) !!}

                                    @if (! empty($p['previous_dpc_date']))
                                        <span class="text-muted small">
{!! __('cgpsc.schedules.history.previously', [
                                            'date' => \Illuminate\Support\Carbon::parse($p['previous_dpc_date'])->format('d/m/Y'),
                                            'time' => \Illuminate\Support\Carbon::parse($p['previous_dpc_time'] ?? '00:00')->format('h:i A'),
                                        ]) !!}
                                        </span>
                                    @endif
                                @endif

                                <div class="small text-muted">
{{ __('cgpsc.schedules.history.by_on', ['actor' => $event->actor_name ?? '—', 'at' => $event->occurred_at->format('d/m/Y H:i')]) }}
                                    @if (! empty($p['timezone'])) · {{ $p['timezone'] }} @endif
                                </div>

                                @if (! empty($p['remarks']))
                                    <div class="small mt-1"><em>{{ __('ui.reason_2') }}</em> {{ $p['remarks'] }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ __('cgpsc.schedules.history.this_dpc_has_not_been_accepted_or_scheduled_yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('cgpsc.schedules.index') }}" class="btn btn-link mt-2">{{ __('cgpsc.schedules.history.back_to_scheduling') }}</a>
        </div>
    </div>
@endsection
