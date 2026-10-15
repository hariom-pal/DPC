@extends('layouts.app')

@section('title', __('candidate.index.my_dpc'))

@section('content')
    {{-- The Promotion Candidate's own DPCs (change item 17).

         Deliberately narrow: this screen lists the DPCs the signed-in officer is
         named on and nothing else. There is no route from here to a Seniority
         List, a Committee or an Evaluation Chart — not a guarded one, none. --}}
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.my_promotion') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('candidate.index.the_departmental_promotion_committee_proceedings') }}
            </p>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr><th>{{ __('ui.dpc') }}</th><th>{{ __('ui.year') }}</th><th>{{ __('ui.promotion') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.action') }}</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td class="text-nowrap"><code>{{ $proposal->dpc_code ?? '—' }}</code></td>
                                    <td>{{ $proposal->dpc_year ?? '—' }}</td>
                                    <td>
                                        {{ $proposal->promotionFromPost?->name ?? '—' }}
                                        <span class="text-muted">→</span>
                                        {{ $proposal->promotionToPost?->name ?? '—' }}
                                    </td>
                                    <td>{{ $proposal->department?->name ?? '—' }}</td>
                                    <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                                    <td class="text-end">
                                        <a href="{{ route('my-dpc.show', $proposal->uuid) }}" {{ __('ui.open_link') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        {{ __('candidate.index.you_are_not_currently_named_in_any_dpc_that_has') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
