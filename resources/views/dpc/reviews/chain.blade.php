@extends('layouts.app')

@section('title', 'Review Chain')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Review Chain</h1>
            <p class="text-muted small mb-3">
                Every correction of this promotion, in order. Nothing here is ever deleted or overwritten — a
                Review supersedes the DPC before it, and both remain on the record.
            </p>

            @if ($chain === [])
                <div class="alert alert-secondary">
                    This DPC has not been reviewed, and does not review another.
                </div>
            @else
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="text-muted small mb-2">Chain <code>{{ $chainUuid }}</code></div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>#</th><th>DPC ID</th><th>Type</th><th>Date</th><th>Status</th><th></th></tr>
                                </thead>
                                <tbody>
                                @foreach ($chain as $entry)
                                    @php $entryProposal = $entry['proposal']; @endphp
                                    <tr @class(['table-active' => $entryProposal?->id === $proposal->id])>
                                        <td>{{ $entry['is_original'] ? '—' : $entry['review_number'] }}</td>
                                        <td><code>{{ $entryProposal?->dpc_code ?? 'not yet issued' }}</code></td>
                                        <td>
                                            @if ($entry['is_original'])
                                                Original
                                            @else
                                                Review {{ $entry['review_number'] }}
                                            @endif
                                        </td>
                                        <td>{{ $entryProposal?->proposal_date?->format('d/m/Y') }}</td>
                                        <td>
                                            @if ($entry['link'] === null && ! $entry['is_original'])
                                                —
                                            @elseif ($entry['is_original'])
                                                <span class="badge text-bg-secondary">Superseded by Review 1</span>
                                            @else
                                                <span class="badge text-bg-primary">{{ $entryProposal?->status?->label() }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($entryProposal !== null && $entryProposal->id !== $proposal->id)
                                                <a class="btn btn-sm btn-link"
                                                   href="{{ route('dpc-proposals.show', $entryProposal) }}">Open</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-2">Chain History</h2>
                        <p class="text-muted small mb-2">
                            Read from the workflow record itself, not from the current state of any proposal.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th style="width:13rem">When</th><th>Event</th><th>By</th><th>Detail</th></tr></thead>
                                <tbody>
                                @forelse ($entries as $entry)
                                    @php $p = $entry->properties ?? []; @endphp
                                    <tr>
                                        <td>{{ $entry->occurred_at?->format('d/m/Y h:i A') }}</td>
                                        <td>{{ $entry->description ?? $entry->event }}</td>
                                        <td>{{ $entry->actor_name ?? '—' }}</td>
                                        <td class="small">
                                            @if (! empty($p['review_number']))
                                                Review {{ $p['review_number'] }}.
                                            @endif
                                            @if (! empty($p['reasons']))
                                                Reasons: {{ implode(', ', (array) $p['reasons']) }}.
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center py-3">
                                        Nothing recorded for this chain yet.
                                    </td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-3">
                <a class="btn btn-sm btn-link" href="{{ route('dpc-proposals.show', $proposal) }}">Back</a>
            </div>
        </div>
    </div>
@endsection
