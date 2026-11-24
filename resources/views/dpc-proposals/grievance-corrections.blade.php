@extends('layouts.app')
@section('title', __('candidate.grievance.ao.queue_heading'))
@section('content')
    {{-- The AO's own entry point to the grievance corrections (2026-11-24). --}}
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">{{ __('candidate.grievance.ao.queue_heading') }}</h1>
            <p class="text-muted small mb-3">{{ __('candidate.grievance.ao.queue_lead') }}</p>

            <div class="card border-0 shadow-sm" id="grievance-corrections">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th>{{ __('ui.sn') }}</th>
                                <th>{{ __('candidate.grievance.review.dpc') }}</th>
                                <th>{{ __('ui.post_from') }}</th>
                                <th>{{ __('ui.post_to') }}</th>
                                <th class="text-center">{{ __('candidate.grievance.review.candidates') }}</th>
                                <th class="text-center">{{ __('candidate.grievance.ao.to_correct') }}</th>
                                <th class="text-center">{{ __('candidate.grievance.ao.with_ah') }}</th>
                                <th class="text-end">{{ __('ui.actions') }}</th>
                            </tr></thead>
                            <tbody>
                                @forelse ($rows as $index => $item)
                                    <tr data-grievance-corrections="{{ $item['proposal']->uuid }}">
                                        <td>{{ $index + 1 }}</td>
                                        <td class="font-monospace">{{ $item['proposal']->citation() }}</td>
                                        <td>{{ $item['proposal']->promotionFromPost?->name }}</td>
                                        <td>{{ $item['proposal']->promotionToPost?->name }}</td>
                                        <td class="text-center">{{ $item['candidates'] }}</td>
                                        <td class="text-center"><span class="badge {{ $item['to_correct'] > 0 ? 'text-bg-warning' : 'text-bg-light border' }}" data-to-correct>{{ $item['to_correct'] }}</span></td>
                                        <td class="text-center">{{ $item['with_ah'] }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-primary btn-sm" href="{{ route('dpc-proposals.grievances.index', $item['proposal']) }}">{{ __('candidate.grievance.review.open') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-3">{{ __('candidate.grievance.ao.queue_none') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
