@extends('layouts.app')

@section('title', 'Explain Result — ' . $result->officer_name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Explain Result</h1>
            <p class="text-muted small mb-3">
                {{ $result->officer_name }} (S.N. {{ $result->sn }})
                · calculation <span class="font-monospace" style="font-size:.8rem">{{ $run->uuid }}</span>
            </p>

            <div class="alert alert-light border small">
                Every statement below is read from what this calculation recorded at the time it ran — the rules it
                executed under, the marks it gave, and the grounds it found. Nothing here is recalculated, so this
                explanation cannot drift from the result it explains, even after the rules change.
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Why this outcome</h2>
                    <ol class="mb-0">
                        @foreach ($explanation as $line)
                            <li class="mb-1">{{ $line }}</li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6">Score</h2>
                            <dl class="row small mb-0">
                                <dt class="col-6">Years scored</dt>
                                <dd class="col-6">{{ implode(', ', $result->score_breakdown['years_used'] ?? []) ?: '—' }}</dd>
                                <dt class="col-6">Base total</dt><dd class="col-6">{{ $result->score_breakdown['base_total'] ?? '—' }}</dd>
                                <dt class="col-6">Tribal-area bonus</dt><dd class="col-6">{{ $result->score_breakdown['tribal_area_bonus'] ?? 0 }}</dd>
                                <dt class="col-6"><strong>Total</strong></dt><dd class="col-6"><strong>{{ $result->score_breakdown['total'] ?? '—' }}</strong></dd>
                                <dt class="col-6">Required</dt><dd class="col-6">{{ $result->verdict['required_score'] ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6">Year-by-year marks</h2>
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Year</th><th>Mark</th></tr></thead>
                                <tbody>
                                    @forelse ($result->score_breakdown['marks_by_year'] ?? [] as $year => $mark)
                                        <tr><td>{{ $year }}</td><td>{{ $mark }}</td></tr>
                                    @empty
                                        <tr><td colspan="2" class="text-muted">No graded CR year on record.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            @if (! empty($result->verdict['failure_reasons']))
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6 text-danger">Grounds for refusal</h2>
                        <ul class="small mb-0">
                            @foreach ($result->verdict['failure_reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="mt-2">
                <a href="{{ route('dpc-proposals.calculations.show', [$proposal, $run->uuid]) }}" class="btn btn-link">← Back to calculation</a>
            </div>
        </div>
    </div>
@endsection
