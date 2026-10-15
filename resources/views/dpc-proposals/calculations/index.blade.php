@extends('layouts.app')

@section('title', __('ui.calculation_history'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.calculation_history') }}</h1>
            <p class="text-muted small mb-3">
{{ __('dpc.calculations.index.subheading', ['from' => $proposal->promotionFromPost->name, 'to' => $proposal->promotionToPost->name]) }}
{!! __('dpc.calculations.index.nothing_replaced_explained') !!}
            </p>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('dpc.calculations.index.calculation') }}</th><th>{{ __('dpc.calculations.index.run') }}</th><th>{{ __('dpc.calculations.index.rule_version') }}</th>
                                    <th class="text-center">{{ __('ui.main') }}</th><th class="text-center">{{ __('ui.waiting') }}</th>
                                    <th>{{ __('ui.status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($runs as $run)
                                    <tr>
                                        <td>
                                            <a href="{{ route('dpc-proposals.calculations.show', [$proposal, $run->uuid]) }}"
                                               class="font-monospace" style="font-size:.8rem">{{ $run->uuid }}</a>
                                            @if ($run->has_pending_decisions)
                                                <br><span class="badge text-bg-warning">{{ __('dpc.calculations.index.awaiting_business_decision') }}</span>
                                            @endif
                                        </td>
                                        <td class="small">
                                            {{ $run->executed_at->format('d-m-Y H:i') }}<br>
                                            {{ __('dpc.calculations.index.executed_by', ['actor' => $run->executedBy?->name ?? __('ui.system')]) }}
                                        </td>
                                        <td class="small">{{ $run->rule_version }}</td>
                                        <td class="text-center">{{ $run->main_list_count }}</td>
                                        <td class="text-center">{{ $run->waiting_list_count }}</td>
                                        <td><span class="badge {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">
                                        {{ __('dpc.calculations.index.no_calculation_has_been_run_on_this_proposal_yet') }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $runs->links() }}
                </div>
            </div>

            <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link mt-2">{{ __('dpc.calculations.index.back_to_proposal') }}</a>
        </div>
    </div>
@endsection
