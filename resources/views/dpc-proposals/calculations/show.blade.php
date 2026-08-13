@extends('layouts.app')

@section('title', 'Calculation Result')

@php
    $officerRow = function ($result) use ($proposal, $run) {
        return [
            'explainUrl' => route('dpc-proposals.calculations.explain', [$proposal, $run->uuid, $result->sn]),
        ];
    };
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
                <h1 class="h4 mb-0">Calculation Result</h1>
                <span class="badge {{ $run->status->badgeClass() }} fs-6">{{ $run->status->label() }}</span>
            </div>
            <p class="text-muted small mb-3">
                {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}
                <br><span class="font-monospace" style="font-size:.8rem">{{ $run->uuid }}</span>
            </p>

            @if ($run->has_pending_decisions)
                <div class="alert alert-warning">
                    <strong>This calculation is provisional.</strong> It met case(s) governed by a rule that is
                    awaiting a business decision. The printed rules were applied and nothing was assumed — but the
                    affected officers or categories below could change once the rule is settled.
                    <ul class="mb-0 mt-2 small">
                        @foreach ($run->pending_decisions ?? [] as $pending)<li>{{ $pending }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Provenance: what a challenge would ask for. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">How this calculation was produced</h2>
                    <ul class="small mb-0">
                        @foreach ($runExplanation as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 text-success">Main List (मुख्य सूचि) — {{ $mainList->count() }}</h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>#</th><th>Officer</th><th>Cat.</th><th>Score</th><th></th></tr></thead>
                                    <tbody>
                                        @forelse ($mainList as $result)
                                            <tr>
                                                <td>{{ $result->list_position }}</td>
                                                <td>
                                                    {{ $result->officer_name }}
                                                    @if ($result->is_super_seat)<span class="badge text-bg-primary">SS</span>@endif
                                                    <div class="text-muted" style="font-size:.75rem">S.N. {{ $result->sn }}</div>
                                                </td>
                                                <td><span class="badge text-bg-light">{{ $result->remark }}</span></td>
                                                <td>{{ $result->verdict['achieved_score'] ?? '—' }}</td>
                                                <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">Explain</a></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted py-3">No officer qualified for the main list.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 text-secondary">Waiting List (अनुपूरक सूचि) — {{ $waitingList->count() }}</h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>#</th><th>Officer</th><th>Cat.</th><th>Score</th><th></th></tr></thead>
                                    <tbody>
                                        @forelse ($waitingList as $result)
                                            <tr>
                                                <td>{{ $result->list_position }}</td>
                                                <td>
                                                    {{ $result->officer_name }}
                                                    @if ($result->is_super_seat)<span class="badge text-bg-primary">SS</span>@endif
                                                    <div class="text-muted" style="font-size:.75rem">S.N. {{ $result->sn }}</div>
                                                </td>
                                                <td><span class="badge text-bg-light">{{ $result->remark }}</span></td>
                                                <td>{{ $result->verdict['achieved_score'] ?? '—' }}</td>
                                                <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">Explain</a></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted py-3">No officer is on the waiting list.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Every officer, including those on neither list, so an absent name is explained. --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">All Officers Assessed ({{ $allResults->count() }})</h2>
                    <p class="text-muted small">
                        Every officer on the evaluation chart appears here, including those on neither list —
                        an absent name is always explained rather than silently missing.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>S.N.</th><th>Officer</th><th>Cat.</th><th>Score</th><th>Zone</th><th>Outcome</th><th>Remark</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($allResults as $result)
                                    <tr>
                                        <td>{{ $result->sn }}</td>
                                        <td>
                                            {{ $result->officer_name }}
                                            @if ($result->is_super_seat)<span class="badge text-bg-primary">SS</span>@endif
                                        </td>
                                        <td>{{ $result->reservation_category_code }}</td>
                                        <td>{{ $result->verdict['achieved_score'] ?? '—' }} / {{ $result->verdict['required_score'] ?? '—' }}</td>
                                        <td>{{ $result->in_zone ? 'In' : 'Out' }}</td>
                                        <td>
                                            @if ($result->is_eligible)
                                                <span class="badge text-bg-success">Eligible</span>
                                            @else
                                                <span class="badge text-bg-danger">Not Eligible</span>
                                            @endif
                                            <div class="text-muted" style="font-size:.75rem">{{ $result->list_type->label() }}</div>
                                        </td>
                                        <td><span class="badge text-bg-light">{{ $result->remark }}</span></td>
                                        <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">Explain</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-2">
                <a href="{{ route('dpc-proposals.calculations.index', $proposal) }}" class="btn btn-link">← Calculation history</a>
                <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link">Back to proposal</a>
            </div>
        </div>
    </div>
@endsection
