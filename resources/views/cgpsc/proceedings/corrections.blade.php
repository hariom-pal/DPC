@extends('layouts.app')

@section('title', 'Correction History')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Correction History</h1>
            <p class="text-muted small mb-3">
                Every correction made during this sitting, in the order it was made, with what the value was
                before, what it became, why, and who made it. Withdrawn corrections remain here — nothing is
                ever removed. This history is read from the workflow record itself, not from the current state
                of the chart.
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:13rem">When</th>
                                    <th>SN</th>
                                    <th>Officer</th>
                                    <th>Field</th>
                                    <th>Year</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Reason</th>
                                    <th>By</th>
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
                                            <span class="badge text-bg-warning">Withdrawn</span>
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
                                    No corrections have been recorded in this sitting.
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
