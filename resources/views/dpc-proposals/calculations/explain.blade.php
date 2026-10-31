@extends('layouts.app')

@section('title', __('ui.screen.explain_result').' — '.$result->officer_name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('dpc.calculations.explain.explain_result') }}</h1>
            <p class="text-muted small mb-3">
{!! __('dpc.calculations.explain.subheading', ['officer' => e($result->officer_name), 'sn' => e($result->sn), 'uuid' => e($run->uuid)]) !!}
            </p>

            <div class="alert alert-light border small">
                {{ __('dpc.calculations.explain.provenance_note') }}
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.calculations.explain.why_this_outcome') }}</h2>
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
                            <h2 class="h6">{{ __('ui.score') }}</h2>
                            <dl class="row small mb-0">
                                <dt class="col-6">{{ __('dpc.calculations.explain.years_scored') }}</dt>
                                <dd class="col-6">{{ implode(', ', $result->score_breakdown['years_used'] ?? []) ?: '—' }}</dd>
                                <dt class="col-6">{{ __('dpc.calculations.explain.base_total') }}</dt><dd class="col-6">{{ $result->score_breakdown['base_total'] ?? '—' }}</dd>
                                <dt class="col-6">{{ __('dpc.calculations.explain.tribal_area_bonus') }}</dt><dd class="col-6">{{ $result->score_breakdown['tribal_area_bonus'] ?? 0 }}</dd>
                                <dt class="col-6"><strong>{{ __('ui.total') }}</strong></dt><dd class="col-6"><strong>{{ $result->score_breakdown['total'] ?? '—' }}</strong></dd>
                                <dt class="col-6">{{ __('dpc.calculations.explain.required') }}</dt><dd class="col-6">{{ $result->verdict['required_score'] ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ __('dpc.calculations.explain.year_by_year_marks') }}</h2>
                            <table class="table table-sm mb-0">
                                <thead><tr><th>{{ __('ui.year') }}</th><th>{{ __('ui.mark') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($result->score_breakdown['marks_by_year'] ?? [] as $year => $mark)
                                        <tr><td>{{ $year }}</td><td>{{ $mark }}</td></tr>
                                    @empty
                                        <tr><td colspan="2" class="text-muted">{{ __('dpc.calculations.explain.no_graded_cr_year_on_record') }}</td></tr>
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
                        <h2 class="h6 text-danger">{{ __('dpc.calculations.explain.grounds_for_refusal') }}</h2>
                        <ul class="small mb-0">
                            @foreach ($result->verdict['failure_reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="mt-2">
                <a href="{{ route('dpc-proposals.calculations.show', [$proposal, $run->uuid]) }}" {{ __('ui.back_to_calculation_link') }}
            </div>
        </div>
    </div>
@endsection
