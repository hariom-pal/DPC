@extends('layouts.app')

@section('title', __('ui.screen.my_dpc').' — '.($proposal->dpc_code ?? ''))

@section('content')
    {{-- Own DPC, own status, timeline and the final result once published
         (change item 17). What is absent is the point: no committee, no
         evaluation, no other candidate, no remarks, no marks. --}}
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h1 class="h4 mb-0">{{ $proposal->dpc_code ?? __('ui.screen.my_dpc') }}</h1>
                    <div class="text-muted small">
                        {{ $proposal->promotionFromPost?->name ?? '—' }} →
                        {{ $proposal->promotionToPost?->name ?? '—' }}
                        ({{ $proposal->dpc_year ?? '—' }})
                    </div>
                </div>
                <span class="badge {{ $proposal->status->badgeClass() }} fs-6">{{ $proposal->status->label() }}</span>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>{{ __('candidate.show.my_details_as_my_department_filed_them') }}</strong></div>
                <div class="card-body">
                    @if ($entry)
                        <dl class="row mb-0">
                            <dt class="col-sm-4 fw-normal">{{ __('ui.name') }}</dt>
                            <dd class="col-sm-8">{{ $entry->name }}</dd>
                            <dt class="col-sm-4 fw-normal">{{ __('candidate.show.employee_number') }}</dt>
                            <dd class="col-sm-8">{{ $entry->employee_number ?? '—' }}</dd>
                            <dt class="col-sm-4 fw-normal">{{ __('candidate.show.seniority_number') }}</dt>
                            <dd class="col-sm-8">{{ $entry->seniority_number }}</dd>
                            <dt class="col-sm-4 fw-normal">{{ __('ui.promotion_from_post') }}</dt>
                            <dd class="col-sm-8">{{ $entry->promotion_from_post ?? '—' }}</dd>
                            <dt class="col-sm-4 fw-normal">{{ __('ui.promotion_to_post') }}</dt>
                            <dd class="col-sm-8">{{ $entry->promotion_to_post ?? '—' }}</dd>
                        </dl>
                        <p class="text-muted small mb-0 mt-3">
                            {{ __('candidate.show.if_any_of_this_is_wrong_it_is_your_department_s') }}
                        </p>
                    @else
                        <p class="text-muted mb-0">{{ __('candidate.show.your_entry_is_not_available_on_this_proposal') }}</p>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>{{ __('candidate.show.final_result') }}</strong></div>
                <div class="card-body">
                    @if ($result === null)
                        {{-- Before publication there is no outcome to state, and
                             stating anything else would be stating an intention. --}}
                        <p class="mb-0 text-muted">
                            {{ __('candidate.show.the_result_of_this_dpc_has_not_been_declared_yet') }}
                        </p>
                    @elseif ($result['promoted'])
                        <p class="mb-1">
                            <span class="badge text-bg-success fs-6">{{ __('candidate.show.recommended_for_promotion') }}</span>
                        </p>
                        <p class="mb-0 text-muted small">
{{ __('candidate.show.declared_promoted', ['date' => $result['declared_at']->format('d-m-Y')]) }}
                        </p>
                    @else
                        <p class="mb-1">
                            <span class="badge text-bg-secondary fs-6">{{ __('candidate.show.not_recommended_for_promotion_in_this_dpc') }}</span>
                        </p>
                        <p class="mb-0 text-muted small">
{{ __('candidate.show.declared_on', ['date' => $result['declared_at']->format('d-m-Y')]) }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>{{ __('candidate.show.progress') }}</strong></div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        @forelse ($timeline as $entryRow)
                            <li class="list-group-item px-0 d-flex justify-content-between">
                                {{-- `.` → `_`: Laravel reads a dot in a translation
                                     key as a step into a nested array. --}}
                                <span>{{ __('candidate.timeline.'.str_replace('.', '_', $entryRow->event)) }}</span>
                                <span class="text-muted small">{{ $entryRow->created_at->format('d-m-Y') }}</span>
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">{{ __('candidate.show.nothing_recorded_yet') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('my-dpc.index') }}" class="btn btn-link mt-2">{{ __('candidate.show.back') }}</a>
        </div>
    </div>
    {{-- Suggestion 8. A candidate can see their DPC here and the data recorded
         about them on Self-Inspection, and had no way to know the other screen
         existed. Linked rather than merged: Self-Inspection deliberately works
         without an account, and that property is worth more than one screen. --}}
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="alert alert-light border small d-flex gap-2 align-items-start">
                @include('partials.icon', ['name' => 'eye', 'size' => 20])
                <div>
                    <strong class="d-block mb-1">{{ __('candidate.show.your_data_heading') }}</strong>
                    {!! __('candidate.show.your_data_lead', [
                        'link' => '<a href="'.e(route('self-inspection.landing')).'">'
                            .e(__('candidate.show.your_data_link')).'</a>',
                    ]) !!}
                </div>
            </div>
        </div>
    </div>

@endsection
