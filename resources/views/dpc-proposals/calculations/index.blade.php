@extends('layouts.app')

@section('title', 'Calculation History')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Calculation History</h1>
            <p class="text-muted small mb-3">
                {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}
                · every calculation ever run on this proposal.
                <strong>Nothing is ever replaced</strong> — recalculating adds a new entry, and the earlier one
                stands exactly as it was.
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Calculation</th><th>Run</th><th>Rule Version</th>
                                    <th class="text-center">Main</th><th class="text-center">Waiting</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($runs as $run)
                                    <tr>
                                        <td>
                                            <a href="{{ route('dpc-proposals.calculations.show', [$proposal, $run->uuid]) }}"
                                               class="font-monospace" style="font-size:.8rem">{{ $run->uuid }}</a>
                                            @if ($run->has_pending_decisions)
                                                <br><span class="badge text-bg-warning">awaiting business decision</span>
                                            @endif
                                        </td>
                                        <td class="small">
                                            {{ $run->executed_at->format('d-m-Y H:i') }}<br>
                                            <span class="text-muted">by {{ $run->executedBy?->name ?? 'system' }}</span>
                                        </td>
                                        <td class="small">{{ $run->rule_version }}</td>
                                        <td class="text-center">{{ $run->main_list_count }}</td>
                                        <td class="text-center">{{ $run->waiting_list_count }}</td>
                                        <td><span class="badge {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">
                                        No calculation has been run on this proposal yet.
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $runs->links() }}
                </div>
            </div>

            <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link mt-2">← Back to proposal</a>
        </div>
    </div>
@endsection
