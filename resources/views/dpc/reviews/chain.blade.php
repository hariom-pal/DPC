@extends('layouts.app')

@section('title', __('ui.review_chain'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.review_chain') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('dpc.reviews.chain.every_correction_of_this_promotion_in_order_noth') }}
            </p>

            @if ($chain === [])
                <div class="alert alert-secondary">
                    {{ __('dpc.reviews.chain.none') }}
                </div>
            @else
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        {!! __('dpc.reviews.chain.chain', ['uuid' => e($chainUuid)]) !!}
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>#</th><th>{{ __('ui.dpc_id') }}</th><th>{{ __('ui.type') }}</th><th>{{ __('dpc.reviews.chain.date') }}</th><th>{{ __('ui.status') }}</th><th></th></tr>
                                </thead>
                                <tbody>
                                @foreach ($chain as $entry)
                                    @php $entryProposal = $entry['proposal']; @endphp
                                    <tr @class(['table-active' => $entryProposal?->id === $proposal->id])>
                                        <td>{{ $entry['is_original'] ? '—' : $entry['review_number'] }}</td>
                                        <td><code>{{ $entryProposal?->dpc_code ?? __('dpc.reviews.chain.not_yet_issued') }}</code></td>
                                        <td>
                                            @if ($entry['is_original'])
{{ __('dpc.reviews.chain.original') }}
                                            @else
                                                {{ __('dpc.reviews.chain.review_number', ['number' => $entry['review_number']]) }}
                                            @endif
                                        </td>
                                        <td>{{ $entryProposal?->proposal_date?->format('d/m/Y') }}</td>
                                        <td>
                                            @if ($entry['link'] === null && ! $entry['is_original'])
                                                —
                                            @elseif ($entry['is_original'])
                                                <span class="badge text-bg-secondary">{{ __('dpc.reviews.chain.superseded_by_review_1') }}</span>
                                            @else
                                                <span class="badge text-bg-primary">{{ $entryProposal?->status?->label() }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($entryProposal !== null && $entryProposal->id !== $proposal->id)
                                                <a class="btn btn-sm btn-link"
                                                   href="{{ route('dpc-proposals.show', $entryProposal) }}">{{ __('ui.open') }}</a>
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
                        <h2 class="h6 mb-2">{{ __('dpc.reviews.chain.chain_history') }}</h2>
                        <p class="text-muted small mb-2">
                            {{ __('dpc.reviews.chain.read_from_the_workflow_record_itself_not_from_th') }}
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th style="width:13rem">{{ __('ui.when') }}</th><th>{{ __('ui.event') }}</th><th>{{ __('ui.by') }}</th><th>{{ __('ui.detail') }}</th></tr></thead>
                                <tbody>
                                @forelse ($entries as $entry)
                                    @php $p = $entry->properties ?? []; @endphp
                                    <tr>
                                        <td>{{ $entry->occurred_at?->format('d/m/Y h:i A') }}</td>
                                        <td>{{ $entry->description ?? $entry->event }}</td>
                                        <td>{{ $entry->actor_name ?? '—' }}</td>
                                        <td class="small">
                                            @if (! empty($p['review_number']))
                                                {{ __('dpc.reviews.chain.review_n', ['number' => $p['review_number']]) }}
                                            @endif
                                            @if (! empty($p['reasons']))
                                                {{ __('dpc.reviews.chain.reasons', ['reasons' => implode(', ', (array) $p['reasons'])]) }}
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center py-3">
                                        {{ __('dpc.reviews.chain.nothing_recorded_for_this_chain_yet') }}
                                    </td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-3">
                <a class="btn btn-sm btn-link" href="{{ route('dpc-proposals.show', $proposal) }}">{{ __('ui.back') }}</a>
            </div>
        </div>
    </div>
@endsection
