@extends('layouts.app')

@section('title', __('dpc.index.dpc_proposals_2'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">{{ __('dpc.index.dpc_proposals') }}</h1>
                {{-- Creating a proposal is the Authorized Officer's alone (PPT
                     S12) — the Administrative Head approves what the AO
                     prepared. `@can` asks the same Policy the controller
                     enforces, so the button and the 403 can never disagree. --}}
                @can('create', App\Domain\Dpc\Models\DpcProposal::class)
                    <div class="d-flex gap-2">
                        <a href="{{ route('dpc-proposals.create') }}" class="btn btn-success btn-sm">{{ __('ui.new_proposal') }}</a>
                        {{-- Review DPC (PPT S35–S37). The screen has existed
                             since M8; until now nothing linked to it. --}}
                        <a href="{{ route('dpc-proposals.reviews.create') }}" class="btn btn-outline-warning btn-sm">
                            {{ __('dpc.index.review_dpc') }}
                        </a>
                    </div>
                @endcan
            </div>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr><th>{{ __('ui.dpc_id') }}</th><th>{{ __('ui.type') }}</th><th>{{ __('dpc.index.post_from_to') }}</th><th>{{ __('ui.proposal_date') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.action') }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td class="text-nowrap">
                                        <code>{{ $proposal->dpc_code ?? '—' }}</code>
                                        @if ($proposal->revision_number > 1)
                                            {!! __('dpc.index.revision', ['number' => $proposal->revision_number]) !!}
                                        @endif
                                    </td>
                                    <td>{{ $proposal->dpcType->name_en }}</td>
                                    <td>{{ $proposal->promotionFromPost->name }} → {{ $proposal->promotionToPost->name }}</td>
                                    <td>{{ $proposal->proposal_date->format('d-m-Y') }}</td>
                                    <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                                    <td class="text-end"><a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.open') }}</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('ui.no_proposals_yet') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">{{ $proposals->links() }}</div>
        </div>
    </div>
@endsection
