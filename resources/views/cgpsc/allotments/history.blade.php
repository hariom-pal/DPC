@extends('layouts.app')

@section('title', __('cgpsc.allotments.history.allotment_history'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.allotments.history.allotment_history') }}</h1>
            <p class="text-muted small mb-1">
                {{ $proposal->department->name }} ·
                <span class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</span> ·
                {{ $proposal->promotionFromPost->name }} → {{ $proposal->promotionToPost->name }}
            </p>

            <div class="alert alert-light border small">
{!! __('cgpsc.allotments.history.lead') !!}
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($events as $event)
                            @php($p = $event->properties ?? [])
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        {{-- Three events share this list since the
                                             clarification of 2026-09-05: allotted,
                                             re-allotted, and returned to the
                                             Chairman because the deadline passed.
                                             The third is what explains the gap
                                             between the other two. --}}
                                        @php($badge = match ($event->event) {
                                            'dpc_cgpsc.reallot' => ['text-bg-warning', 'cgpsc.allotments.history.reallotted'],
                                            'dpc_cgpsc.allotment_returned' => ['text-bg-danger', 'cgpsc.allotments.history.returned'],
                                            default => ['text-bg-primary', 'cgpsc.allotments.history.allotted'],
                                        })
                                        <span class="badge {{ $badge[0] }}">{{ __($badge[1]) }}</span>
                                        <strong>{{ $p['allotted_to_name'] ?? '—' }}</strong>

                                        @if ($event->event === 'dpc_cgpsc.allotment_returned' && ! empty($p['complete_by']))
                                            <span class="text-muted small">
                                                {{ __('cgpsc.allotments.history.not_concluded_by', [
                                                    'date' => \Illuminate\Support\Carbon::parse($p['complete_by'])->format('d/m/Y'),
                                                ]) }}
                                            </span>
                                        @endif

                                        @if (! empty($p['previous_allotted_to_name']))
                                            <span class="text-muted small">
{!! __('cgpsc.allotments.history.previously', ['name' => e($p['previous_allotted_to_name'])]) !!}
                                            </span>
                                        @endif

                                        <div class="small text-muted">
{{ __('cgpsc.allotments.history.by_on', [
                                                'actor' => $p['allotted_by_name'] ?? $event->causer?->name ?? '—',
                                                'at' => $event->occurred_at->format('d/m/Y H:i'),
                                            ]) }}
                                            @if (! empty($p['default_dpc_date']))
{{ __('cgpsc.allotments.history.default_dpc_date', ['date' => \Illuminate\Support\Carbon::parse($p['default_dpc_date'])->format('d/m/y')]) }}
                                            @endif
                                        </div>

                                        @if (! empty($p['remarks']))
                                            <div class="small mt-1"><em>{{ __('ui.reason_2') }}</em> {{ $p['remarks'] }}</div>
                                        @endif
                                    </div>

                                    @if (! empty($p['allotment_uuid']))
                                        <span class="font-monospace text-muted" style="font-size:.7rem">{{ $p['allotment_uuid'] }}</span>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ __('cgpsc.allotments.history.this_dpc_has_not_been_allotted_yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('cgpsc.allotments.index') }}" class="btn btn-link mt-2">{{ __('cgpsc.allotments.history.back_to_allotment') }}</a>
        </div>
    </div>
@endsection
