@extends('layouts.app')

@section('title', __('dpc.calculations.show.calculation_result'))

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
                <h1 class="h4 mb-0">{{ __('dpc.calculations.show.calculation_result') }}</h1>
                <span class="badge {{ $run->status->badgeClass() }} fs-6">{{ $run->status->label() }}</span>
            </div>
            <p class="text-muted small mb-3">
                {{ $proposal->promotionFromPost->name }} → {{ $proposal->promotionToPost->name }}
                <br><span class="font-monospace" style="font-size:.8rem">{{ $run->uuid }}</span>
            </p>

            @if ($run->has_pending_decisions)
                <div class="alert alert-warning">
                    {!! __('dpc.calculations.show.provisional_explained') !!}
                    <ul class="mb-0 mt-2 small">
                        @foreach ($run->pending_decisions ?? [] as $pending)<li>{{ $pending }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Provenance: what a challenge would ask for. --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('dpc.calculations.show.how_this_calculation_was_produced') }}</h2>
                    <ul class="small mb-0">
                        @foreach ($runExplanation as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            {!! __('dpc.calculations.show.main_list_heading', ['count' => $mainList->count()]) !!}
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>#</th><th>{{ __('ui.officer') }}</th><th>{{ __('ui.cat') }}</th><th>{{ __('ui.score') }}</th><th></th></tr></thead>
                                    <tbody>
                                        @forelse ($mainList as $result)
                                            <tr>
                                                <td>{{ $result->list_position }}</td>
                                                <td>
                                                    {{ $result->officer_name }}
                                                    @if ($result->is_super_seat)<span class="badge text-bg-primary">{{ __('ui.ss') }}</span>@endif
                                                    <div class="text-muted" style="font-size:.75rem">S.N. {{ $result->sn }}</div>
                                                </td>
                                                <td><span class="badge text-bg-light">{{ $result->remark }}</span></td>
                                                <td>{{ $result->verdict['achieved_score'] ?? '—' }}</td>
                                                <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.calculations.show.explain') }}</a></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted py-3">{{ __('dpc.calculations.show.no_officer_qualified_for_the_main_list') }}</td></tr>
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
                            {!! __('dpc.calculations.show.waiting_list_heading', ['count' => $waitingList->count()]) !!}
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>#</th><th>{{ __('ui.officer') }}</th><th>{{ __('ui.cat') }}</th><th>{{ __('ui.score') }}</th><th></th></tr></thead>
                                    <tbody>
                                        @forelse ($waitingList as $result)
                                            <tr>
                                                <td>{{ $result->list_position }}</td>
                                                <td>
                                                    {{ $result->officer_name }}
                                                    @if ($result->is_super_seat)<span class="badge text-bg-primary">{{ __('ui.ss') }}</span>@endif
                                                    <div class="text-muted" style="font-size:.75rem">S.N. {{ $result->sn }}</div>
                                                </td>
                                                <td><span class="badge text-bg-light">{{ $result->remark }}</span></td>
                                                <td>{{ $result->verdict['achieved_score'] ?? '—' }}</td>
                                                <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.calculations.show.explain') }}</a></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted py-3">{{ __('dpc.calculations.show.no_officer_is_on_the_waiting_list') }}</td></tr>
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
                    {!! __('dpc.calculations.show.all_officers_heading', ['count' => $allResults->count()]) !!}
                    <p class="text-muted small">
                        {{ __('dpc.calculations.show.every_officer_on_the_evaluation_chart_appears_he') }}
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>{{ __('ui.s_n') }}</th><th>{{ __('ui.officer') }}</th><th>{{ __('ui.cat') }}</th><th>{{ __('ui.score') }}</th><th>{{ __('dpc.calculations.show.zone') }}</th><th>{{ __('dpc.calculations.show.outcome') }}</th><th>{{ __('ui.remark') }}</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($allResults as $result)
                                    <tr>
                                        <td>{{ $result->sn }}</td>
                                        <td>
                                            {{ $result->officer_name }}
                                            @if ($result->is_super_seat)<span class="badge text-bg-primary">{{ __('ui.ss') }}</span>@endif
                                        </td>
                                        <td>{{ $result->reservation_category_code }}</td>
                                        <td>{{ $result->verdict['achieved_score'] ?? '—' }} / {{ $result->verdict['required_score'] ?? '—' }}</td>
                                        <td>{{ $result->in_zone ? 'In' : 'Out' }}</td>
                                        {{-- Item 24 of the change register of 2026-08-27: four
                                             statuses, not two. This was a boolean until the
                                             register, which printed "Not Eligible" against a CIR
                                             candidate — whose promotion is *held pending a
                                             record*, not refused. --}}
                                        @php($status = $result->status())
                                        <td>
                                            <span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
                                            <div class="text-muted" style="font-size:.75rem">{{ $result->list_type->label() }}</div>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-light">{{ $result->remark }}</span>
                                            {{-- "CIR" alone does not say what is awaited, and the
                                                 officer is entitled to know which record is
                                                 holding their promotion (item 19). --}}
                                            @foreach ($result->gateCitations() as $citation)
                                                <div class="text-muted" style="font-size:.75rem">{{ $citation }}</div>
                                            @endforeach
                                        </td>
                                        <td><a href="{{ $officerRow($result)['explainUrl'] }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.calculations.show.explain') }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-2">
                <a href="{{ route('dpc-proposals.calculations.index', $proposal) }}" class="btn btn-link">{{ __('dpc.calculations.show.calculation_history') }}</a>
                <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link">{{ __('dpc.calculations.show.back_to_proposal') }}</a>
            </div>
        </div>
    </div>
@endsection
