@extends('layouts.app')

@section('title', __('ui.correction_history'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.correction_history') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.proceedings.corrections.every_correction_made_during_this_sitting_in_the') }}
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:13rem">{{ __('ui.when') }}</th>
                                    <th>{{ __('ui.sn') }}</th>
                                    <th>{{ __('ui.officer') }}</th>
                                    <th>{{ __('ui.field') }}</th>
                                    <th>{{ __('ui.year') }}</th>
                                    <th>{{ __('ui.from') }}</th>
                                    <th>{{ __('ui.to') }}</th>
                                    <th>{{ __('ui.reason') }}</th>
                                    <th>{{ __('ui.by') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($entries as $entry)
                                @php
                                    $p = $entry->properties ?? [];
                                    $withdrawn = $entry->event === 'dpc_proceeding.correction_withdrawn';
                                @endphp
                                <tr @class(['table-warning' => $withdrawn])>
                                    <td>
                                        {{ $entry->occurred_at?->format('d/m/Y h:i A') }}
                                        @if ($withdrawn)
                                            <span class="badge text-bg-warning">{{ __('cgpsc.proceedings.corrections.withdrawn') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $p['sn'] ?? '—' }}</td>
                                    <td>{{ $p['officer_name'] ?? '—' }}</td>
                                    <td>
                                        @if (! empty($p['field']))
                                            {{ \App\Domain\Proceeding\Enums\CorrectableField::tryFrom($p['field'])?->label() ?? $p['field'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $p['year'] ?? '—' }}</td>
                                    <td><del class="text-muted">{{ $p['previous_value'] ?? '(blank)' }}</del></td>
                                    <td><strong>{{ $p['new_value'] ?? '(blank)' }}</strong></td>
                                    <td class="small">{{ $p['reason'] ?? '' }}</td>
                                    <td class="small">{{ $p['applied_by_name'] ?? $entry->actor_name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-muted text-center py-3">
                                    {{ __('cgpsc.proceedings.corrections.no_corrections_have_been_recorded_in_this_sittin') }}
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
