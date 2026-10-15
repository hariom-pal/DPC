@extends('layouts.app')

@section('title', __('ui.proceeding_history'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.proceeding_history') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.proceedings.history.every_step_of_this_dpc_s_proceeding_in_the_order') }}
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:14rem">{{ __('ui.when') }}</th>
                                    <th>{{ __('ui.event') }}</th>
                                    <th>{{ __('ui.by') }}</th>
                                    <th>{{ __('ui.detail') }}</th>
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
{{ __('cgpsc.proceedings.history.scheduled_for', ['date' => $properties['dpc_date'], 'time' => $properties['dpc_time'] ?? '']) }}
                                        @endif
                                        @if (! empty($properties['opened_at']))
{{ __('cgpsc.proceedings.history.opened_at', ['at' => \Illuminate\Support\Carbon::parse($properties['opened_at'])->format('d/m/Y h:i A')]) }}
                                        @endif
                                        @if (! empty($properties['remarks']))
                                            <div>{{ $properties['remarks'] }}</div>
                                        @endif
                                        @if (! empty($properties['proceeding_uuid']))
                                            <div class="text-muted">
{!! __('cgpsc.proceedings.history.reference', ['uuid' => e($properties['proceeding_uuid'])]) !!}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">
                                    {{ __('cgpsc.proceedings.history.nothing_has_been_recorded_for_this_proceeding_ye') }}
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <a class="btn btn-sm btn-link" href="{{ route('cgpsc.proceedings.show', $proceeding) }}">{{ __('ui.back') }}</a>
            </div>
        </div>
    </div>
@endsection
